# Security

## User Model

`users` extends Laravel's default table with:

| Column | Notes |
|---|---|
| `tenant_id` | Nullable ULID, FK → `tenants.id`, `ON DELETE RESTRICT`. Null only for platform admins. |
| `status` | `active` \| `inactive` \| `suspended` (`User::STATUS_*`). Non-active users cannot log in. |
| `is_platform_admin` | Boolean. Distinguishes platform-level admins from tenant users — no `user_type` enum yet; introduce one if more platform-level categories emerge. |
| `last_login_at` / `last_login_ip` | Set on every successful login. |
| `deleted_at` | Soft delete. |

**Enforced in two layers:**
1. Postgres `CHECK` constraint `users_platform_admin_tenant_consistency` — `is_platform_admin = true` requires `tenant_id IS NULL`, and vice versa. Skipped on SQLite (used only by the test suite; SQLite can't `ALTER TABLE ADD CONSTRAINT`).
2. `User::booted()` `saving` guard — same rule, at the application layer, so it's covered by tests regardless of DB driver and gives a clear exception instead of a raw constraint-violation error.

`users.id` remains Laravel's default bigint auto-increment (not ULID like `tenants.id`) — not changed in this checkpoint since it wasn't requested; flagged for a future decision once other tables start referencing `users.id`.

**Email uniqueness is global**, not scoped per tenant (Laravel's default `unique` constraint, unchanged). Two different tenants currently cannot share a user email. Revisit deliberately if that's ever needed — a per-tenant unique constraint has a Postgres NULL-uniqueness subtlety for platform admins (all having `tenant_id IS NULL`) that needs care.

## Platform Super Admin

- `is_platform_admin = true`, `tenant_id = null`.
- Not scoped to any tenant's data — no `BelongsToTenant` trait applies to `User` at all (see below).
- Can only log in via requests where **no** tenant is resolved (base domain or a reserved subdomain) — attempting to log in on any tenant's subdomain is rejected, even with correct credentials.

## Tenant Users

- `is_platform_admin = false`, `tenant_id` required (enforced by both layers above).
- Can only log in via their **own** tenant's subdomain — a correct password on the wrong subdomain is rejected. The resolved `Tenant` (bound server-side by `ResolveTenant`, see [`architecture.md`](architecture.md)) is cross-checked against the user's stored `tenant_id`. `tenant_id` is never read from the request body.
- Login is rejected if the user's own `status` isn't `active`, or if their tenant's `status` isn't `active`.

## Why `User` doesn't use `BelongsToTenant`

Tenant-owned business data (future: employees, leave, documents) uses the `BelongsToTenant` trait (global scope + auto-fill from the container-bound tenant). `User` deliberately does **not**:

- Login must look up a user by email before any tenant is "current" for that request in a meaningful sense.
- Platform admins need cross-tenant visibility of users (for future admin tooling).
- Auto-filling `tenant_id` from whatever subdomain a request happens to arrive on would make tenant assignment implicit — assignment must be explicit (checked directly against user input to `create()`/`update()`, not inferred).

## Login Flow (`LoginRequest::authenticate()`)

Order matters, and is deliberate:

1. Rate limit check (5 attempts per email+IP, standard Laravel `RateLimiter` pattern used by Breeze/Fortify).
2. `Auth::attempt()` — credentials verified **first**.
3. Only after credentials are proven valid: check the request arrived on the correct domain for this user (own subdomain for tenant users, base domain for platform admins).
4. Then check `status` (user) and tenant `status`.
5. On success: update `last_login_at`/`last_login_ip`, regenerate the session ID (session fixation protection).

Checking domain/status *after* credentials avoids leaking account-state information (e.g. "this account is suspended") to someone who hasn't already proven they hold the correct password.

**Audited.** `login.success`, `login.failed` (at every rejection point — bad credentials, wrong domain, inactive account, inactive tenant), and `logout` are all written to `audit_logs` directly from `LoginRequest`/`AuthenticatedSessionController` via `AuditLogger` — see [Audit Logging](#audit-logging) below.

**CSRF**: standard Laravel `web` middleware group protection is active on `/login` and `/logout` (verified against the real running app — an unauthenticated POST without a valid CSRF token is correctly rejected with 419).

**JSON-only responses**: since no frontend/login UI exists yet, `/login` and `/logout` are configured (`bootstrap/app.php`) to always render JSON, including on validation failure — otherwise Laravel's default behavior tries to redirect back to a nonexistent form.

## Tenant-Session Isolation — a real vulnerability found in Checkpoint 7

**This is app-wide, not employee-specific.** Found while hardening the
Employee Records endpoints, but the bug (and the fix) affects every
current and future authenticated, tenant-scoped route.

### The bug

`SESSION_DOMAIN=.peopleos.test` (leading dot, set in Checkpoint 2 for
subdomain-based tenancy) means a session cookie is valid across **every**
subdomain of `peopleos.test`. A user logged in on `uesl.peopleos.test`
has a browser session cookie that gets sent automatically if they (or a
malicious link, or a stray bookmark) visit `airpeace.peopleos.test` too.

Nothing checked for this. `hasPermission()` only verifies the user's *own*
tenant is active — it never compares against the tenant the *current
request* resolved to. `BelongsToTenant`'s global scope filters queries by
the request-resolved tenant (correct for tenant *identification*, but
blind to *who's asking*). The result: an authenticated tenant-A user
hitting `GET /api/v1/employees` on tenant B's subdomain got a clean `200`
with tenant B's employee data. Confirmed directly (not theorized) before
fixing — see `git log` for Checkpoint 7's investigation.

This was reachable with nothing more than a URL — no exploit tooling,
no crafted headers, just an already-authenticated user's browser
automatically sending a cookie it legitimately has.

### The fix

`App\Http\Middleware\EnsureTenantMatchesAuthenticatedUser` (aliased
`tenant.matches`), applied on every authenticated tenant-scoped route
**after** `auth` and **before** any `permission:` check:

- Tenant user: request must have resolved to *their own* `tenant_id`, or reject (403).
- Platform admin: request must have resolved to **no** tenant (base domain), or reject (403) — no tenant-impersonation feature exists yet.
- No authenticated user: pass through (let `auth` handle it).

Every rejection writes a `critical`-severity audit log
(`tenant.mismatch_blocked`, module `security`) — this is exactly the kind
of event worth being loud about, since a real occurrence likely means a
stale session, a shared/leaked cookie, or an actual attack attempt.

### Final middleware order rule for tenant-scoped authenticated routes

```
auth  →  tenant.matches  →  permission:{key}
```

1. **Authentication before tenant/user context checks** — you can't check
   whether a user belongs to a tenant before knowing who the user is.
2. **Tenant resolution itself must not allow user-controlled tenant
   switching** — `ResolveTenant` derives the tenant purely from the
   `Host` header (server-controlled routing), never from request body or
   headers a client could set arbitrarily. `tenant.matches` is the
   second half of this guarantee: even though *which* tenant is resolved
   is safe, *whether the authenticated user should be allowed there* still
   needed its own check.
3. **Permission checks happen after both are known** — `hasPermission()`
   assumes it's being asked about a user who has already been confirmed
   to belong to the current tenant context; without `tenant.matches`
   running first, that assumption was silently false.
4. **Tenant-scoped model binding/queries must not run before tenant
   context is established** — the Checkpoint 6 `ResolveTenant` ordering
   fix (`prependToGroup`, must run before `SubstituteBindings`) is the
   other half of this; both fixes are required together, neither alone is
   sufficient.

**Every future tenant-scoped authenticated route must include
`tenant.matches`.** It's not automatic/global — it's applied per-route
(alongside `auth`) in `routes/api.php`, the same way `permission:` is.
Forgetting it on a new route silently reopens this exact hole.

### A second bug found while testing the fix

Testing `tenant.matches` with a plain (non-JSON) unauthenticated request
surfaced a separate, unrelated pre-existing bug: Laravel's default `auth`
middleware tries to redirect unauthenticated non-JSON requests to a named
`login` route — which doesn't exist in this app (auth is JSON-only, no
HTML login form anywhere). This crashed with an uncaught
`RouteNotFoundException` (500), instead of a clean 401. Fixed via
`redirectGuestsTo(fn () => null)` in `bootstrap/app.php`, so guests always
get a plain 401 regardless of `Accept` header. Verified against the real
running app: `curl -H "Accept: application/json"` (and without that
header too) to `/api/v1/employees` unauthenticated now returns `401`, not
a 500.

## RBAC

### Platform roles vs. tenant roles

Same `is_platform_*` / `tenant_id` pattern as `User`, applied to `Role`:
`is_platform_role = true` ⟺ `tenant_id IS NULL`. Enforced at both the
Postgres `CHECK` constraint layer and the `Role::booted()` `saving` guard,
same reasoning as `users`.

**Tenant roles are not shared templates.** Each tenant owns its own row
for every role — "UESL's HR Manager" and "Air Peace's HR Manager" are
different database rows, even though they share a name and (initially) a
permission set. This is what makes "a role from Tenant A grants nothing in
Tenant B" a real, enforced guarantee rather than a naming convention that
could accidentally be violated. The tradeoff: seeding N tenants × M role
types creates N×M rows. Deemed worth it for correctness at this stage —
revisit if the row count becomes a real problem (a shared-template layer
that tenant roles are instantiated from is a plausible future refactor,
but adds complexity not justified yet).

### Permission naming convention

`{category}.{action}`, e.g. `employees.view`, `platform.tenants.create`.
Permissions prefixed `platform.` are always `is_platform_permission =
true`; everything else is tenant-level. The `is_platform_permission` flag
is the actual enforcement mechanism — the naming convention is a
readability aid, not itself a security boundary (a permission's scope is
checked via the column, not by string-matching the key).

### Assignment-time guards, not just query-time checks

`Role::givePermissionTo()`, `User::assignRole()`, and
`User::grantPermission()` (in `app/Models/Concerns/HasPermissions.php`)
each validate *before* writing to the database:

- A role can only be given a permission of matching scope (platform role
  ↔ platform permission, tenant role ↔ tenant permission).
- A user can only be assigned a role of matching scope, and — for tenant
  roles — only a role belonging to their **own** tenant.
- A user can only be directly granted a permission of matching scope.

All three throw `RuntimeException` on violation. **These are plain method
logic, not Eloquent model events** — deliberately, because
`DatabaseSeeder`'s `WithoutModelEvents` (see "Known Limitations") would
silently bypass event-based guards during seeding. Method-logic guards
stay active regardless, which is why seeding mistakes here surface
immediately rather than producing silently-wrong data.

### `hasPermission()` — the check every caller should use

`User::hasPermission(string $key): bool`, implementing the full check in
order (fails closed at every step — inactive user, inactive tenant, or an
unknown key all return `false`, never throw):

1. Is the user active? (`status`)
2. If a tenant user: is their tenant active?
3. Does the permission key exist at all?
4. Does the user have it via any assigned role?
5. Does the user have it via a direct grant?

Reusable everywhere: `$user->hasPermission('employees.view')` directly,
`->middleware('permission:employees.view')` on routes
(`App\Http\Middleware\EnsurePermission`, aliased `permission`), or
`$user->can('employees.view')` / `@can('employees.view')` via a
`Gate::before()` hook in `AppServiceProvider` that delegates to the same
method — so policies/gates and the permission system never diverge.

### Direct permission grants

`user_permissions`: a user can be granted a permission directly, outside
role assignment (`granted_by`, `reason` recorded). Scope-checked the same
way as role assignment. **No expiry yet** — every direct grant is
permanent until explicitly revoked (`User::revokePermission()`, a hard
delete of the row). Temporary, time-boxed permissions are an explicitly
deferred future checkpoint (see "Future: Temporary Permissions" below) —
not built now to avoid a half-finished expiry mechanism nobody's
enforcing yet (no scheduled job checks/revokes on expiry).

### Tenant isolation rules (enforced, not just documented)

- Tenant roles belong to exactly one tenant; platform roles belong to
  none — DB `CHECK` + app guard.
- A tenant user can only be assigned a role from their own tenant — app
  guard, tested (`RbacTest::test_tenant_user_cannot_receive_role_from_another_tenant`,
  `test_role_from_tenant_a_does_not_grant_access_to_tenant_b`).
- A tenant user can never be assigned a platform role, and a platform
  admin can never be assigned a tenant role — app guard, tested.
- Direct grants are scope-checked the same way — a platform admin can't
  be directly granted a tenant permission, or vice versa.

### Future: Temporary Permissions

Not built yet. When added, the plan is an `expires_at` (nullable
timestamp) on `user_permissions`, `hasPermission()` additionally checking
`expires_at IS NULL OR expires_at > now()`, and a scheduled job to prune
(or just leave — the timestamp check alone is sufficient for correctness;
pruning is a cleanliness/reporting concern, not a security one) expired
rows. Deferred rather than half-built now, per your "no sensitive shortcut
for speed" rule — an expiry column nobody enforces yet would be worse than
no expiry column.

## Audit Logging

### Table design

`audit_logs` is append-only — no `updated_at`, no soft delete. Enforced at
the model layer: `AuditLog::save()` throws if called on an existing row,
`AuditLog::delete()` always throws. This holds regardless of what future
code touches the model — not dependent on "no UI exists to do it yet."
Full column reference in [`database.md`](database.md#audit_logs).

**`tenant_id` is always explicit, never inferred.** Unlike tenant-owned
business models (which use `BelongsToTenant`'s auto-fill from the
container-bound `Tenant`), `AuditLog` doesn't use that trait — audit
events happen in contexts (login, CLI, seeders) where an ambient bound
tenant would be unreliable or simply absent. Every call site passes
`tenantId` explicitly (or explicitly `null` for platform-level events).

### The `AuditLogger` service

`app/Services/Audit/AuditLogger.php` — two entry points:

- `AuditLogger::log(...)` — full control, every field explicit.
- `AuditLogger::logFor($actor, ...)` — convenience wrapper that derives
  `actorUserId`/`actorType`/`tenantId` from a `?User $actor` (falls back to
  `system`/`null` when `$actor` is `null`, e.g. seeder-driven actions).

Designed to be callable from anywhere — controllers, model methods,
services, jobs, authentication events — since it takes plain scalars/arrays,
not a `Request` object or other HTTP-bound dependency.

### Automatic sensitive-value masking

`old_values`/`new_values` are scanned for known-sensitive key
names/substrings (`password`, `token`, `secret`, `bank`,
`account_number`, `national_id`, `passport`, `salary`, `ssn`, `tax_id` —
case-insensitive substring match, so `bank_account_number`,
`national_id_number`, etc. are all caught without enumerating every exact
field name) and masked to `***MASKED***`. **This happens automatically,
regardless of whether the caller remembered to exclude the field** —
defense in depth, not just caller discipline. `metadata` is *not*
masked — don't put sensitive data there; it's meant for small contextual
tags (e.g. `attempted_email` on a failed login), not record snapshots.

### What's currently logged

| Action | Module | Where |
|---|---|---|
| `login.success` | `auth` | `LoginRequest::authenticate()` |
| `login.failed` | `auth` | `LoginRequest::authenticate()` — at every rejection point (bad credentials, wrong domain, inactive account, inactive tenant) |
| `logout` | `auth` | `AuthenticatedSessionController::destroy()` |
| `role.assigned` | `rbac` | `User::assignRole()` |
| `role.removed` | `rbac` | `User::removeRole()` |
| `permission.granted` | `rbac` | `User::grantPermission()` |
| `permission.revoked` | `rbac` | `User::revokePermission()` |

**Not yet logged, and why:**
- `Role::givePermissionTo()` (attaching a permission to a *role*, as
  opposed to a direct grant to a *user*) — not in the originally-scoped
  event list for this checkpoint. A role's permission set changing is
  still security-relevant (it affects everyone holding that role); revisit
  before RBAC management gets a real admin UI.
- Tenant creation/update — no tenant management code path exists yet
  beyond `TenantSeeder` (a dev bootstrapping script, not a meaningful
  security event to log). Wire this when a real Platform Super Admin
  tenant-management feature is built.

`assignRole()`/`removeRole()`/`grantPermission()`/`revokePermission()` all
accept an optional `?User $performedBy` parameter — `null` when called
from a seeder/system context (`actor_type` becomes `system`), the acting
user when called from a real request context.

### Platform-level vs. tenant-level audit logs

Same pattern as everywhere else: `tenant_id IS NULL` for platform-level
events (e.g. a platform admin's own role assignment), non-null for
tenant-scoped events. Tested directly
(`AuditLoggingTest::test_platform_level_action_can_create_audit_log_with_nullable_tenant_id`).

### Current limitations

- **No read/viewing endpoint** — this checkpoint is write-only (recording
  events), by design (no Audit UI, no export, no search — explicitly
  out of scope). Tenant-scoped read access control is future work.
- **No audit trail for `Role::givePermissionTo()`** — see above.
- **No audit trail for tenant creation/update** — see above.
- **`RbacTest`'s pre-Checkpoint-5 role/permission assertions don't pass an
  actor** — they still work (actor is optional), but predate audit
  logging, so don't demonstrate the `performedBy` parameter. New tests in
  `AuditLoggingTest` do.

### Future: Audit UI / Export

Not built. When it is: a read-only viewing interface, tenant-scoped for
tenant users (never cross-tenant), unrestricted for platform admins
viewing platform-level logs; export as a separate, explicitly-permissioned
capability (`audit.export`, already seeded as a permission key, unused
until then); no SIEM integration planned at this stage.

## Employee Records

The first real tenant-owned business module — see
[`api.md`](api.md) for the full endpoint reference and
[`database.md`](database.md#employees) for the table design.

### Permission mapping

| Permission | Grants |
|---|---|
| `employees.view` | List/view employees (excluding sensitive fields) |
| `employees.create` | Create employee records |
| `employees.update` | Update employee records |
| `employees.delete` | Soft-delete employee records |
| `employees.view_sensitive` | See `personal_email`/`phone` in API responses, on top of `employees.view` |
| `employees.export` | Reserved, not implemented this checkpoint |
| `employees.view_team` | View direct reports (own via `/me/direct-reports`, or another employee's via the admin endpoint) — Checkpoint 13 |
| `employees.update_manager` | Assign/change/remove an employee's manager — Checkpoint 13 |

### `personal_email` / `phone` are sensitive — a decision, not a given

Your spec left it to me whether these count as sensitive enough to gate
behind `employees.view_sensitive`. Decided **yes** — treated consistently
in two places:

1. **API responses** (`EmployeeResource`) — `null` unless the requester
   has `employees.view_sensitive`.
2. **Audit logs** (`AuditLogger`) — masked in `old_values`/`new_values`
   the same way `password`/`bank`/`national_id`/etc. already are.

`work_email` is *not* gated — it's business contact information, not
personal.

### Tenant isolation — a real bug found and fixed during this checkpoint

Tracing the actual Laravel middleware pipeline (from a stack trace
captured back in Checkpoint 3) revealed that `SubstituteBindings` (which
resolves `{employee}` in a route) ran **before** `ResolveTenant`, because
`ResolveTenant` had been registered with `appendToGroup` (added to the
*end* of the `web` group), while `SubstituteBindings` is part of Laravel's
earlier default stack. That meant implicit route-model-binding on any
tenant-scoped model would resolve **before** a tenant was bound in the
container — `BelongsToTenant`'s global scope wouldn't have been active
yet for that lookup. A real cross-tenant IDOR risk, not a theoretical one.

**Fixed** by switching to `prependToGroup` (`bootstrap/app.php`), so
`ResolveTenant` now runs first. **Also** added an explicit tenant-ownership
check in `EmployeeController` (`ensureBelongsToCurrentTenant()`) as
defense in depth, independent of the global scope or middleware order —
matching the "not the only safeguard" principle in `docs/architecture.md`.
Both are exercised by
`EmployeeApiTest::test_user_cannot_view_employee_from_another_tenant_by_id`,
which passed on the first run after the fix — but existed specifically to
catch this class of bug, and would have caught it before the fix too, had
it been written first.

**Consequence for future modules:** any future tenant-scoped model reached
via route-model-binding should still add its own explicit ownership check
in the controller — don't rely solely on the global scope, even with the
ordering now fixed. If a future middleware change reintroduces ordering
sensitivity, the explicit check is the backstop.

**This is a different bug from the one found in Checkpoint 7** (below) —
this one was about *route-model-binding resolving before tenant
identification*; Checkpoint 7's was about *tenant identification not
being checked against the authenticated user at all*. Both needed fixing;
neither fix would have caught the other.

### Audit events

`employee.created`, `employee.updated` (only when something actually
changed — `updated_at`/`updated_by` bumps alone don't trigger a log
entry), `employee.deleted` (soft delete). All include `old_values`/
`new_values` where relevant, with `personal_email`/`phone` masked.

## Document Repository

The second tenant-owned business module — see [`api.md`](api.md) for the
endpoint reference and [`database.md`](database.md#document_categories)
for the table design.

### Private storage — the core rule

Files live on the `local` disk (`storage/app/private`), Laravel 11's
default private root — **not** `storage/app/public` (which is symlinked
to `public/storage` and *is* web-accessible). No document is ever placed
under the public disk. Verified directly during this checkpoint: a
real (non-faked) file written through the same code path the controller
uses was confirmed to exist on disk but **not** exist under
`public/storage` — not just asserted, checked.

Additional layers beyond "which disk":

- **Randomized stored filename** (`Str::random(40)` + extension) —
  `original_filename` is kept as display-only metadata, never used for
  the actual storage path or serving. This also structurally prevents
  double-extension attacks (`resume.pdf.exe`) — the file is never stored
  or served under any name derived from the client-provided one.
- **Tenant- and employee-segregated storage path**
  (`employee-documents/{tenant_id}/{employee_id}/{random}.{ext}`) —
  defense in depth on top of the database-level tenant scoping, in case
  storage-path logic is ever reused somewhere that doesn't go through the
  DB layer.
- **No signed temporary URLs** — Laravel's `temporaryUrl()` isn't
  supported by the `local` driver (only cloud drivers like S3). Every
  download goes through `EmployeeDocumentController::download()`, which
  runs the full permission/tenant/ownership/sensitivity check chain
  before streaming the file — per your explicit fallback instruction.
- **Checksum** (SHA-256) computed at upload time and stored — not yet
  used to verify integrity on download (no corruption-detection feature
  built), but available for a future one without a schema change.

### File validation

Extension **and** detected MIME type must both match the allow-list
(`pdf`, `doc`, `docx`, `jpg`, `jpeg`, `png`) — Laravel's `File` validation
rule inspects actual file content (via PHP's fileinfo), not just the
client-declared `Content-Type` header or filename extension, so a
renamed executable (`malware.exe` → `malware.pdf`) fails validation
because its real content doesn't match PDF's expected signature.

Max size: **10MB** — not specified in the original spec, chosen as a
reasonable default for HR document scans/PDFs (`StoreEmployeeDocumentRequest::MAX_FILE_SIZE_KB`).
Easy to change; flagged as a decision, not a given.

### Permission mapping

| Permission | Grants |
|---|---|
| `documents.view` | List/view document metadata (excluding sensitive documents without `view_sensitive`) |
| `documents.upload` | Upload documents |
| `documents.download` | Download document files |
| `documents.delete` | Soft-delete/archive documents |
| `documents.view_sensitive` | See and download sensitive documents, on top of `documents.view`/`documents.download` |
| `documents.approve` | Reserved — no approval workflow endpoint exists yet (`approved_by`/`approved_at` columns exist, unused) |

**`documents.view_sensitive` is a new, dedicated permission — not a reuse
of `employees.view_sensitive`.** A document's sensitivity is a property
of the document/category, independent of whether specific *employee*
fields are sensitive; conflating the two would have made both harder to
reason about. Seeded in `PermissionSeeder`, tested directly.

### Sensitive documents: excluded entirely, not field-masked

Unlike `employees.view_sensitive` (which masks specific *fields* like
`personal_email`/`phone` while still showing the rest of the record), a
sensitive *document* without `documents.view_sensitive` is **excluded
entirely** from list results, and `show`/`download` return `404` (not
`403`) — the same "don't reveal existence" posture used for cross-tenant
access. This is deliberate: there's no sensible "partial view" of a
document the way there is for a masked phone number, and a sensitive
document's mere existence (e.g. a disciplinary letter) can itself be
worth protecting.

`is_sensitive` on `employee_documents` is inherited from the chosen
`document_category.is_sensitive` at upload time — not a field the
uploader sets directly, since no category-management endpoint exists yet
to make that a meaningful independent choice.

### Object-level checks — three layers, the Checkpoint 7 pattern applied again

1. `tenant.matches` middleware (session belongs to the right tenant at all).
2. `BelongsToTenant` global scope (query/route-model-binding filtered to the resolved tenant).
3. **Two** explicit controller checks, not one: `ensureEmployeeBelongsToCurrentTenant()` *and* `ensureDocumentBelongsToEmployee()` — a document ID valid for a *different employee in the same tenant* must still be rejected. This is a genuinely new check beyond Checkpoint 6/7's pattern, since documents are nested under employees (`/employees/{employee}/documents/{document}`), not top-level.

### Audit events

`document.uploaded`, `document.viewed` (single-document `show` only —
**not** logged per-row on `index`/list, a scope decision: logging every
row in a paginated list separately seemed noisy without clear
investigative value; revisit if a real need for list-level audit
granularity emerges), `document.downloaded`, `document.deleted`.

**What's deliberately excluded from every audit log entry:**
- File contents — never read into the log, only metadata.
- `storage_path`/`storage_disk`/`stored_filename` — the *location* of a
  private file is itself sensitive-adjacent information; logs record
  `document_category_id`, `title`, `mime_type`, `file_size`, and similar
  safe metadata only.

### Current limitations

- No document category management endpoint — categories exist only as a
  schema + factories for tests; created via seeder/tinker for now.
- No approval workflow — `documents.approve` permission and
  `approved_by`/`approved_at` columns are reserved, unused placeholders.
- No malware scanning — file content is validated by type/size/detected
  MIME only, not scanned for malicious payloads.
- No cloud storage (S3, etc.) — local private disk only, matches your
  explicit "don't build ahead of a real need" instruction for this
  checkpoint.
- No document-policy-acknowledgement link — that's a distinct future
  module (Policy Management), not built yet.
- `checksum` is computed and stored but not yet used to verify integrity
  on download.

## Document Category Management

Closes the gap flagged at the end of Checkpoint 8: `document_categories`
now has a real management API, not just a schema categories are seeded
into by hand. See [`api.md`](api.md) for the endpoint reference.

### Dedicated permissions, not reused ones

`document_categories.view/create/update/delete` — deliberately **not**
reusing `documents.*`, per your explicit recommendation: managing what
categories exist is an admin/configuration action, distinct from
uploading/viewing/downloading individual employee documents. Seeded in
`PermissionSeeder`. Tenant Admin receives them automatically (it's
granted *all* current non-platform permissions dynamically at seed time —
confirmed directly: Tenant Admin's permission count matches the total
non-platform permission count exactly after this checkpoint's seeding,
42). **HR Manager's grants were deliberately left unchanged** — it
doesn't need category-management permissions to reference an existing
category ID when uploading a document; a tenant admin can grant it
explicitly if a specific tenant wants HR Manager to also manage
categories.

### A real Checkpoint 8 validation gap found and fixed

`StoreEmployeeDocumentRequest`'s `document_category_id` validation used
`Rule::exists('document_categories', 'id')->where('tenant_id', ...)` — a
**raw** database existence check that bypasses Eloquent entirely,
including `DocumentCategory`'s `SoftDeletes` global scope. A soft-deleted
or `status=inactive` category could have been attached to a *new*
document upload, directly contradicting this checkpoint's own rule that
inactive/deleted categories must not be available for new uploads.
Fixed by adding explicit `where('status', 'active')` and
`whereNull('deleted_at')` to the raw rule — found while implementing this
checkpoint's own requirements, not a separate bug hunt.

### Deletion is soft-delete only — there is no hard-delete path

The `DELETE` endpoint calls `$documentCategory->delete()` (soft delete)
and nothing else. This means "a category used by active documents can't
be unsafely hard-deleted" is true **structurally** — there's no code path
in this API that could hard-delete a category at all, used or not. Soft
deleting a category:

- Does not touch existing `employee_documents.document_category_id`
  foreign keys — a soft delete only sets `deleted_at`, it doesn't cascade
  or null anything.
- Existing documents referencing the now-deleted category are completely
  unaffected — verified directly with a test that uploads a document
  under a category, soft-deletes the category, and confirms the document
  row is untouched and the category row still exists (soft-deleted).
- The category becomes unavailable for **new** uploads (via the
  validation fix above) without breaking anything already using it.

### A second in-memory-attribute bug found and fixed while testing

`DocumentCategory::create()` with an omitted optional field (e.g.
`applies_to` not sent in the request) doesn't backfill that column's
database default into the in-memory model — Eloquent sends an `INSERT`
without that column, Postgres applies its schema default, but the
just-created `$category` object in PHP memory never learns that value.
`DocumentCategoryResource` then crashed (`Attempt to read property
"value" on null`) trying to render `$category->applies_to->value`. Fixed
by explicitly defaulting `applies_to`/`is_sensitive`/`is_required`/
`requires_expiry_date` in the controller before `create()`, the same
pattern already used for `status`. Caught immediately by the first test
run — a good example of why every checkpoint runs the real test suite
before considering anything done, not just a read-through of the code.

### Audit events

`document_category.created`, `document_category.updated` (only when
something actually changed, same pattern as Employee/EmployeeDocument),
`document_category.deleted`. Same tenant-scoping, same "safe old/new
values only" discipline as every other module's audit trail.

## Policy Management

Builds on the Document Repository / Document Category foundation — see
[`api.md`](api.md) for the full endpoint reference and
[`database.md`](database.md#policies) for the table design.

### The core design problem: no user-to-employee link exists

There is no `user_id` on `employees` and no `employee_id` on `users` —
authentication accounts and HR employee records are entirely separate.
This directly affects who can "acknowledge" a policy: there's no way to
derive "which employee is the currently logged-in user."

**Decision (this checkpoint): `POST /policies/{policy}/acknowledge` is
admin/HR-recorded only.** It requires an explicit `employee_id` in the
request body — there's no session-derived alternative. Every
acknowledgement created this checkpoint has
`acknowledgement_method = admin_recorded` (never `web`, which is reserved
for a future genuine self-service flow once real user-to-employee linking
exists).

> **Resolved in Checkpoint 11** — see
> [User ↔ Employee Linking](#user--employee-linking) below. The
> `employee_id` requirement became optional (resolved from the caller's
> own verified link by default), and `policies.acknowledge` is now safe
> to grant to the Employee role. The deviation explained below was a
> deliberate, temporary posture, not a permanent design decision — it
> held only as long as no verified identity link existed.

**Consequence for the role mapping — a deliberate deviation from the
spec's suggestion, documented as instructed:** the suggested mapping gave
Employee `policies.acknowledge`. I did **not** grant it, at the time.
Here's why: if a rank-and-file Employee-role user could call
`/acknowledge` with an arbitrary `employee_id`, they could record an
acknowledgement on behalf of *any* employee in the tenant (already
enumerable via the existing `employees.view` permission most Employee-role
users also hold) — not just themselves. That's exactly the "insecure
shortcut" instruction I was told not to take. `policies.acknowledge`
stayed with HR-trusted roles (Tenant Admin, HR Manager) only, until real
self-service — requiring actual identity verification — existed.

### Role mapping (as seeded in `RoleSeeder`, updated in Checkpoint 11)

| Role | Permissions |
|---|---|
| Tenant Admin | All (automatic — granted every current tenant permission dynamically at seed time) |
| HR Manager | view, create, update, publish, assign, acknowledge, view_acknowledgements — **not** archive/export (per the spec's own suggested carve-out) |
| HR Officer | view, create, update, assign, view_acknowledgements — matches the spec exactly |
| Employee | view, **acknowledge** — safe as of Checkpoint 11, see below |
| Auditor | view, view_acknowledgements |

### The acknowledgement flow, precisely

1. **Assign**: `POST /policies/{policy}/assign` — requires the policy to
   have a `current_version_id` (i.e. be published; assigning a draft
   isn't meaningful). Creates one `pending` `PolicyAcknowledgement` row
   per employee, pointing at the policy's *current* version. Duplicate
   (employee, version) pairs are silently skipped (reported in the
   response, not an error) — enforced at the database level via a unique
   constraint, not just an app-layer check.
2. **Acknowledge**: `POST /policies/{policy}/acknowledge` — finds the
   employee's `pending` row for this policy. If the policy has been
   *republished* since that employee was assigned (their row's
   `policy_version_id` no longer matches `policy.current_version_id`),
   the request is rejected (`409`) rather than silently acknowledging a
   superseded version. **No auto-reassignment on republish** — a known
   limitation, not built this checkpoint (would need its own workflow
   design).
3. Publishing a *new* version never deletes the old one — its status
   moves to `archived`. Acknowledgement history for the archived version
   remains queryable via `GET /policies/{policy}/acknowledgements`.

### `employee_document_id` on `policy_versions` — a real schema mismatch, not hidden

The spec asks for a field linking a policy version to an uploaded file in
the document repository. But `employee_documents.employee_id` is
`NOT NULL` — a policy document isn't owned by any single employee. The
field is implemented literally (nullable FK, tenant-validated), but in
practice requires an existing `employee_documents` row, which requires
picking *some* employee to "own" it — semantically wrong for a
tenant-wide policy document. `content` (plain text) is the primary,
fully-supported path this checkpoint; attaching a real file cleanly needs
a future general "tenant documents" (non-employee-scoped) table, out of
scope here.

### Permission-value-dependent authorization

Archiving a policy (`status → archived` via `PATCH /policies/{policy}`)
requires `policies.archive` *in addition to* `policies.update` — checked
inside the controller, since route-level middleware can't inspect a
request body value. This is why HR Manager (who has `update` but not
`archive`) is correctly blocked from archiving even though they can reach
the same endpoint for every other field.

### Audit events

`policy.created`, `policy.updated`, `policy.archived` (same endpoint as
`updated`, distinguished by which field changed), `policy.version_created`,
`policy.published`, `policy.assigned` (one log entry per employee
assigned, not one per batch — keeps `target`/metadata specific), `policy.acknowledged`.

### A real bug affecting 3 models, found and fixed this checkpoint

See [`database.md`](database.md#a-real-bug-found-and-fixed-in-this-checkpoint-affecting-3-existing-models)
— `created_by`/`updated_by` were silently dropped on `Employee` and
`DocumentCategory` since Checkpoints 6 and 9 respectively, due to being
excluded from `$fillable`. Fixed for those two models plus the two new
ones (`Policy`, `PolicyVersion`) introduced this checkpoint.

## User ↔ Employee Linking

Closes the identity gap flagged throughout Policy Management above — see
[`api.md`](api.md#user--employee-linking) for the endpoint reference and
[`database.md`](database.md#employees) for the column design.

### Design

`employees.user_id` — nullable, **unique**, FK → `users.id` `SET NULL`,
plus `linked_at`/`linked_by` for provenance. A single unique constraint
enforces the 1:1 relationship in both directions: an employee can have at
most one linked user (obviously — one FK column), and a user can be
linked to at most one employee (enforced by the *unique* constraint on
that FK, not by a second column anywhere). Both are checked again at the
app layer in `LinkEmployeeUserRequest::withValidator()` for a clean 422
instead of a raw constraint-violation error.

### Linking rules (all enforced, not just documented)

1. Only `employees.link_user` can create a link, `employees.unlink_user`
   to remove one — dedicated permissions, not reused from
   `employees.update` (linking an identity is a materially different,
   higher-trust action than editing a name field).
2. Both the target employee (route parameter) and the target user
   (`user_id` in the request body) must belong to the **current** tenant
   — the employee via the usual `ensureBelongsToCurrentTenant()` (404 if
   not), the user via `Rule::exists('users', 'id')` scoped to
   `tenant_id` in `LinkEmployeeUserRequest` (422 if not — a validation
   failure, not a 404, since the employee side of the request did resolve
   correctly; only the referenced `user_id` is invalid).
3. The target user must be `is_platform_admin = false` and
   `status = active` — a platform admin or inactive user cannot be
   linked at all, checked in the same validation rule.
4. An already-linked employee cannot be re-linked to a different user
   without unlinking first (422) — same for an already-linked user.
5. Linking and unlinking both write to `audit_logs`
   (`employee.user_linked` / `employee.user_unlinked`), including
   `target_user_id` for the linked/unlinked user.
6. `GET /api/v1/me/employee` requires **no dedicated permission** — it's
   inherently self-scoped (resolves `$request->user()->employee`), the
   same posture as a "whoami" endpoint. Returns `404` if the caller has
   no linked employee — a safe, unambiguous response, not an empty `200`
   that a client might mistake for "employee exists but is empty."

### Why linking itself stays HR/admin-only, not self-service

The spec's own suggested design (nullable `user_id`, admin-performed
linking) was followed as given — an employee cannot link *themselves* to
a user account via any endpoint. This is deliberate: self-linking would
require its own identity-proofing story (how does the system know
`employee #482` really is the person logged in as `user #17`?) that
doesn't exist yet — an invitation-token flow is the natural future
answer, explicitly out of scope this checkpoint (see "Do not build" in
the checkpoint spec). HR/admin performing the link, based on
out-of-band identity verification they're already trusted to do, is the
safe interim posture.

### The acknowledgement redesign: two paths, one endpoint

`PolicyController::acknowledge()` now resolves which employee the
acknowledgement is *for* in one of two ways:

1. **Self-acknowledgement** — `employee_id` omitted from the request
   body (or explicitly equal to the caller's own linked employee).
   Resolved via `$request->user()->employee?->id`. Requires only
   `policies.acknowledge`. Recorded as `acknowledgement_method: web`.
2. **Admin-recorded, on behalf of someone else** — `employee_id`
   explicitly provided and it differs from the caller's own link (or the
   caller has no link at all). Requires `policies.acknowledge` **and**
   `policies.assign` — reusing the existing "trusted to manage
   assignments" permission rather than inventing a new one for this.
   Recorded as `acknowledgement_method: admin_recorded`.

**Why this is safe to grant Employee-role users `policies.acknowledge`
now, when it wasn't safe last checkpoint:** an Employee-role user never
holds `policies.assign` (see the role mapping table above). Whatever
`employee_id` they submit, if it doesn't resolve to their own link, the
`policies.assign` check rejects the request with `403` — they cannot
acknowledge on behalf of anyone but themselves, regardless of what value
they put in the request body. This is the same principle as every other
"don't trust client-declared identity" rule in this app
(`tenant_id`/`created_by`/`updated_by` never accepted from request
input) applied to a new field.

A user with **no** linked employee and no explicit `employee_id` gets a
clear `422` ("You have no linked employee record...") rather than a
confusing `404` — distinguishing "you have nothing to acknowledge with"
from "no pending acknowledgement exists for that employee."

### Permission mapping

| Permission | Grants |
|---|---|
| `employees.link_user` | Link a user account to an employee record |
| `employees.unlink_user` | Remove an existing link |

Granted to Tenant Admin (automatic) and HR Manager — HR Manager is
already trusted with `employees.create`/`employees.update`, and linking
a user account is a natural extension of that trust. **Not** granted to
HR Officer or any other role this checkpoint — no spec instruction to do
so, and narrower is the safer default until a real need is demonstrated.

### Audit events

`employee.user_linked`, `employee.user_unlinked` — both include
`target_user_id` (the linked/unlinked user), `auditable_type` = `Employee`,
`auditable_id` = the employee's id.

### Known limitations

- **No self-linking / invitation flow** — see above. HR/admin performs
  every link.
- **No employee profile self-update** — linking gives read access to
  `/me/employee`; it does not add any new write capability. An employee
  still cannot edit their own record via any endpoint.
- **No manager-approval workflow** for linking — explicitly out of scope
  this checkpoint (per the "do not build" list), consistent with no
  Leave Management dependency existing yet either.
- **Re-linking after unlink requires a fresh HR/admin action** — no
  "pending re-link request" or self-service re-link exists.

## Leave Management

The first tenant-owned **workflow** module, not just CRUD-with-a-status-
field — see [`api.md`](api.md#leave-management) for the endpoint
reference and [`database.md`](database.md#leave_types) for the table
design. Built directly on Checkpoint 11's User ↔ Employee Linking:
leave request creation is self-service by construction, not by later
retrofit.

### Permission mapping (as seeded in `RoleSeeder`, updated in Checkpoint 14)

| Role | Permissions |
|---|---|
| Tenant Admin | All (automatic — every current tenant permission) |
| HR Manager | All leave permissions, per your explicit suggested mapping — `leave_types.*` + `leave.view`/`view_all`/`request`/`approve`/`reject`/`cancel` (includes `request`/`cancel` so an HR Manager who is also a linked employee can manage their own leave) |
| HR Officer | `leave_types.view`, `leave.view`, `leave.view_all`, `leave.approve`, `leave.reject` |
| Employee | `leave.view`, `leave.request`, `leave.cancel` — **no** `leave.view_all` |
| Auditor | `leave.view`, `leave.view_all` |
| Line Manager | `leave.view`, `leave.view_team`, `leave.approve`, `leave.reject` — **no** `leave.view_all`/`leave.request`/`leave.cancel` (Checkpoint 14; the latter two weren't requested, avoiding scope creep) |

### Why `leave.view_all` is needed, not skippable

Without it, there would be no way to distinguish "see only my own leave
requests" from "see everyone's in the tenant" — `leave.view` alone gates
whether the endpoints are reachable *at all*, and `leave.view_all`
(checked inside the controller, not route middleware, since it changes
*query scope* rather than *reachability*) determines whether that access
is self-scoped or tenant-wide. Without this second permission, granting
`leave.view` to the Employee role for self-service viewing would also
have to either (a) let them see everyone's leave (wrong), or (b)
hard-code "Employee role = own only" into the controller by role name
rather than by permission (fragile, and inconsistent with every other
permission check in the app being data-driven, not role-name-driven).
`leave.view_all` avoids both.

### Why Line Manager now safely gets `leave.approve`/`leave.reject` (Checkpoint 14)

Checkpoints 10 and 12 both withheld a suggested permission grant
(`policies.acknowledge` from Employee; `leave.approve`/`leave.reject`
from Line Manager) because granting it would have created an unscoped
blast radius — anyone holding the permission could act on *any* record
tenant-wide, with no feature yet in place to narrow that down safely.
Checkpoint 13 built that missing feature (`ManagerHierarchyService`).
This checkpoint is what actually *uses* it: `LeaveRequestController::
approve()`/`reject()` now require, in addition to holding
`leave.approve`/`leave.reject`, that the caller either holds
`leave.view_all` (HR/Admin-level, tenant-wide) or
`ManagerHierarchyService::directlyManages()` confirms they directly
manage the request's employee. A caller with `leave.approve` but
neither qualification gets `403` — see "Manager-Hierarchy-Scoped Leave
Approval" below for the full design. This is the resolution of the
pattern flagged in Checkpoints 10/12/13, not a fourth instance of it.

## Manager-Hierarchy-Scoped Leave Approval

Uses `ManagerHierarchyService::directlyManages()` (Checkpoint 13) to
scope `LeaveRequestController::approve()`/`reject()` — see
[`architecture.md`](architecture.md#manager-hierarchy-scoped-leave-approval)
and [`api.md`](api.md#leave-management) for the design rationale and
endpoint reference. No new routes, no schema change — this checkpoint
is purely an authorization-logic and permission-catalog change on the
existing `POST /leave-requests/{leaveRequest}/approve`/`reject`
endpoints.

### The design decision: direct reports only (as instructed)

`directlyManages()` — not `isManagerOf()` — is used for every check
this checkpoint. A Line Manager can approve/reject leave only for
employees whose `manager_employee_id` points directly at their own
linked employee. A grandparent manager cannot act on a grandchild's
leave request, even though the full chain-walk (`isManagerOf()`) exists
and could technically answer that question. **This is a deliberate
policy choice, not a technical limitation** — indirect-report approval
may need its own company-specific policy (does a skip-level manager get
the same authority? does it depend on the direct manager's absence?)
that wasn't specified, so it's left as explicit future work rather than
guessed at.

### The authorization rule, precisely

```
allowed = holds(leave.approve or leave.reject)      // route middleware
      AND NOT (target employee == caller's own linked employee)  // self-block, checked first
      AND (
            holds(leave.view_all)                                     → scope = 'hr_admin'
            OR (caller has a linked employee AND directlyManages(caller, target)) → scope = 'direct_manager'
          )
```

Holding `leave.approve`/`leave.reject` is **necessary but no longer
sufficient** — the first time this shape of rule appears in the app
(every earlier permission check was "does the caller hold this one
permission," full stop). `resolveApprovalScope()` returns `'hr_admin'`,
`'direct_manager'`, or `null`; a `null` result aborts with `403`,
regardless of which route-level permission got the caller past
middleware. Confirmed directly (Refinement 6 — the most important
regression test this checkpoint): a user holding `leave.approve` alone,
with no `leave.view_all` and no management relationship to the target
employee, is rejected — this is exactly the Checkpoint 12 behavior that
would have been unsafe to keep once Line Manager holds the same
permission.

### Self-approval/self-rejection block runs first (Refinement 3)

`ensureNotOwnRequestForApprovalAction()` is checked *before*
`resolveApprovalScope()`, so it applies uniformly regardless of which
scope would otherwise apply. A dual-role user — a Tenant Admin, HR
Manager, or Line Manager who is *also* a linked employee — is blocked
from approving/rejecting their own leave request whether they'd
otherwise qualify as `hr_admin` or `direct_manager`. Tested directly for
both roles (`test_hr_admin_cannot_approve_own_leave_request`,
`test_line_manager_cannot_approve_own_request`).

### Manager-scoped approval requires a linked employee, not role alone (Refinement 2)

`resolveApprovalScope()`'s `direct_manager` branch only evaluates
`directlyManages()` if `$user->employee` is non-null. A user holding
`leave.approve` via the Line Manager role but with no linked employee
record cannot qualify for `direct_manager` scope at all — role
membership is never treated as a proxy for an actual verified
management relationship. Tested directly
(`test_user_without_linked_employee_cannot_manager_approve`).

### Leave visibility: three tiers, exact behavior (Refinement 1)

| Permission held | `GET /leave-requests` (list) | `GET /leave-requests/{id}` (single) |
|---|---|---|
| `leave.view_all` | Every leave request in the tenant | Any request in the tenant — `200` |
| `leave.view_team` (no `view_all`) | Own request(s) + direct reports' — via `directReportsOf()`, **direct only** | Own, or a direct report's — `200`; otherwise `404` |
| `leave.view` only | Own request(s) only | Own only — `200`; otherwise `404` |
| No linked employee, no `leave.view_all` | Empty list, `200` | `404` |
| `leave.view_team` held but no linked employee | Empty list, `200` (no employee to resolve direct reports from) | `404` |

A user holding **both** an HR-level and a manager-level permission
(e.g. `leave.view_all` *and* `leave.view_team`) gets the broader access
(`leave.view_all`'s tenant-wide scope) — checked first in both
`index()` and `ensureCanView()`, so the narrower `leave.view_team` logic
is never reached once `leave.view_all` is present. Still fully
tenant-scoped either way — `leave.view_all` never crosses tenant
boundaries (see `BelongsToTenant` and the pre-existing route-model-
binding tenant-scope behavior documented under Checkpoint 13 above).

### `leave.view_team` vs. `leave.view_all` — deliberately not the same permission

`leave.view_all` already existed (Checkpoint 12) and means tenant-wide
visibility — granting it to Line Manager would give them visibility
into *every* employee's leave, not just their own reports, which is
exactly the over-broad grant your refinements explicitly ruled out.
`leave.view_team` is new this checkpoint, seeded only to Line Manager
(and not, deliberately, to HR Officer/Auditor/Employee — none of those
roles needed it this checkpoint; HR Officer/Auditor already have
`leave.view_all`, and Employee has neither).

### Audit metadata (Refinement 5)

Every `leave_request.approved`/`leave_request.rejected` audit entry's
`metadata` now includes:

```json
{
  "leave_request_id": "01h...",
  "employee_id": "01h...",
  "leave_type_id": "01h...",
  "actor_user_id": 7,
  "actor_employee_id": "01h...",
  "approval_scope": "direct_manager",
  "old_status": "pending",
  "new_status": "approved"
}
```

`actor_employee_id` is `null` when the acting user has no linked
employee (e.g. a pure HR/Admin account with no employee record) — not
omitted, so its absence is explicit rather than ambiguous.
`approval_scope` lets a future audit review distinguish "an HR/Admin
acted with tenant-wide authority" from "a direct manager acted on their
own report" without needing to cross-reference the actor's role
separately. `rejection_reason` remains excluded from `new_values`
(masked, per the Checkpoint 12 rule) and is never present in `metadata`
either — confirmed directly with a real free-text reason
("Recovering from surgery") asserted absent from both the masked
`new_values` and the (never-masked) `metadata` JSON.

### Employee self-service rules (enforced, not just documented)

1. A linked employee can create a leave request **for themselves only**
   — `StoreLeaveRequestRequest` has no `employee_id` field at all.
   `employee_id` is always resolved server-side from
   `$request->user()->employee`. A stray `employee_id` in the request
   body is silently ignored, not honored and not rejected — consistent
   with how `tenant_id`/`created_by` are handled everywhere else in this
   app (fields the client cannot influence simply aren't validated
   fields).
2. A user with **no** linked employee cannot create a leave request at
   all — `422` ("You have no linked employee record...").
3. An employee can view, submit, and cancel **only their own** requests
   — see the two-tier object-check design below.
4. An employee can never approve or reject **any** request, including
   their own — `leave.approve`/`leave.reject` are never granted to the
   Employee role, and even if a user held them while also being linked
   to the request's employee, `ensureNotOwnRequestForApprovalAction()`
   blocks it explicitly (matters most for Tenant Admin/HR Manager users
   who may also be linked employees themselves — see Refinement 4 below).

### Two different object-level checks, given different HTTP status codes on purpose

- **Visibility** (`show`/`index`): does the caller have *any* legitimate
  path to know this resource exists — own request, or `leave.view_all`?
  Failure → `404`. Same "don't reveal existence" posture used
  throughout this app for cross-tenant/cross-parent access.
- **Self-service action ownership** (`update`/`submit`/`cancel`): is the
  caller specifically *this* request's owner, regardless of what else
  they can see? Failure → `403`. An HR user with `leave.view_all` can
  already see the resource via `show()`/`index()` — a `404` here would
  be misleading (they already know it exists), so `403` ("you can see
  it, you're just not allowed to act on it") is the more honest
  response. This deliberately differs from the "hide existence" 404
  convention used elsewhere, because here existence genuinely isn't
  secret from that caller.

### Cancel is strictly self-only — a deliberate scope limit, confirmed on refinement

Even though the suggested role mapping gives Tenant Admin/HR Manager
`leave.cancel` too, there is no "cancel on behalf of employee" capability
built this checkpoint — `ensureOwnLeaveRequest()` requires the caller's
own linked employee to match, with no `leave.view_all`-style exception.
Unlike policy acknowledgement (which has `policies.assign` as an
explicit "act on behalf of" permission), no such permission exists for
leave cancellation in this checkpoint's catalog, and building one wasn't
requested. An HR Manager holding `leave.cancel` today can cancel their
*own* leave requests; cancelling someone else's on their behalf (e.g. an
employee too unwell to self-cancel) is out of scope, documented as a
limitation below.

### Refinement 4 — self-approval is blocked independent of role

`ensureNotOwnRequestForApprovalAction()` checks the acting user's own
linked employee against the request's `employee_id` on every
`approve()`/`reject()` call, regardless of what permission got them
there. This specifically matters for Tenant Admin/HR Manager accounts
that are *also* linked to an employee record — without this check, a
dual-role Tenant Admin could approve their own leave request purely by
virtue of holding `leave.approve` tenant-wide. Tested directly
(`test_employee_cannot_approve_own_request`).

### Status transitions — centrally enforced, not per-action

`App\Enums\LeaveRequestStatus::canTransitionTo()` is the single source
of truth:

```
draft   → pending, cancelled
pending → approved, rejected, cancelled
approved / rejected / cancelled → (terminal, nothing)
```

Every write action calls `ensureTransitionAllowed()` before mutating
state — `409` (state conflict), not `422` (request validation failure),
same distinction `PolicyController::acknowledge()` already makes for a
superseded policy version. This is what makes "approved → pending",
"rejected → approved", double-approval, etc. impossible regardless of
which endpoint is called, rather than needing each action to
independently reimplement the same guard.

### `total_days` is always server-computed

Calculated in the controller (`Carbon` diff, inclusive of both
endpoints) before every `create()`/relevant `update()` — never trusted
from request input even when present in the body. Confirmed directly:
a request sending `total_days: 999` alongside real 3-day dates persists
`total_days: 3`.

### Refinement 3 — `cancelled_by` added for accountability

Not in your original field list (`cancelled_at` only). Added a nullable
`cancelled_by` FK, matching `approved_by`/`rejected_by`'s reasoning: a
timestamp alone records *when* something happened, not *who* did it.
Since cancel is strictly self-only this checkpoint, `cancelled_by` will
currently always equal the request's own linked employee's user — but
the column exists independently so a future "HR cancels on behalf of"
capability doesn't need a schema change, and so the audit story is
consistent with approve/reject rather than a visible asymmetry.

### `reason` / `rejection_reason` are masked in audit log snapshots

Both added to `AuditLogger`'s `SENSITIVE_KEY_PATTERNS` (`app/Services/
Audit/AuditLogger.php`) — free-text leave reasons can carry medical or
otherwise personal information (a real example tested directly:
"Undergoing chemotherapy treatment" is masked to `***MASKED***` in
`audit_logs.new_values`, confirmed via both a feature test and a live
`psql` check against the real database). The underlying `leave_requests`
row still stores the real value (needed for the actual workflow) — only
the audit trail's duplicate copy is masked, consistent with the
"defense in depth, not just caller discipline" principle already
documented for `password`/`bank`/`personal_email`/etc.

### Audit events

`leave_type.created`, `leave_type.updated`, `leave_type.deleted`,
`leave_request.created`, `leave_request.updated` (PATCH, only when
something actually changed), `leave_request.submitted`,
`leave_request.approved`, `leave_request.rejected`,
`leave_request.cancelled`. Every `leave_request.*` event includes
`employee_id`/`leave_type_id` in `metadata` (not masked — these are
identifiers, not personal content) alongside the usual
`tenant_id`/`actor_user_id`/`auditable_type`/`auditable_id`.

### Leave type deletion is safe by construction

Same reasoning as `DocumentCategoryController::destroy()`
(Checkpoint 9): the `DELETE` endpoint only ever soft-deletes — there is
no hard-delete code path in this API at all. A leave type referenced by
existing leave requests is always safe to "delete": `leave_requests.
leave_type_id`'s `RESTRICT` foreign key means the *database* would
refuse a hard delete anyway, but it's moot since the only delete path is
a soft delete, which doesn't touch the FK at all. `StoreLeaveRequestRequest`/
`UpdateLeaveRequestRequest` already exclude inactive/soft-deleted leave
types from new/edited requests (the same `Rule::exists()` + explicit
`where('status', 'active')->whereNull('deleted_at')` fix required by
your quality-review instruction — see `database.md`).

### Current limitations

- **`total_days` is inclusive calendar days, including weekends and
  public holidays** — not business days. No business-day calculation,
  weekend exclusion, or country/location holiday calendar exists yet.
  Half-day leave is not supported (`total_days` is a whole number).
- **No leave balances or accrual engine** — `leave_types.
  max_days_per_year` is stored but not enforced anywhere; a leave
  request exceeding it is not rejected. Deliberately deferred per your
  instruction — building a placeholder here would risk exactly the
  "half-finished, nobody enforcing it" trap already avoided for
  temporary permissions (see RBAC section above).
- **Manager-hierarchy-scoped approval is direct-reports-only** (Checkpoint 14) —
  see [Manager-Hierarchy-Scoped Leave Approval](#manager-hierarchy-scoped-leave-approval)
  above. A manager cannot approve/reject an indirect (grandchild)
  report's leave; this is a deliberate scope decision, not a gap to be
  quietly closed later without a fresh policy decision.
- **`leave.cancel` has no "on behalf of" capability** — cancellation is
  strictly self-only this checkpoint, even for roles holding
  `leave.cancel` tenant-wide in principle. See above.
- **`leave_types.requires_document`/`requires_approval` are stored but
  not enforced** — no document-attachment capability exists on leave
  requests, and every request goes through the same approval flow
  regardless of `requires_approval`'s value.
- **No notifications, email alerts, or calendar integration** —
  explicitly out of scope this checkpoint.
- **No frontend UI** — API-only, same as every module so far.

### Future

- Leave balances / accrual engine (`max_days_per_year` already reserved
  on `leave_types`).
- Business-day calculation, weekend exclusion, country/location holiday
  calendars, half-day leave — see "Current limitations" above.
- Indirect (skip-level/grandparent) manager approval — `ManagerHierarchyService::
  isManagerOf()` already exists and could answer "is this a
  manager-of-a-manager" today; extending `resolveApprovalScope()` to use
  it is a deliberate future policy decision (see Checkpoint 14 above),
  not a technical blocker.
- Notifications / email approval / reminders for pending requests.
- Calendar integration.
- Document attachment requirement enforcement (`requires_document` is
  stored, unused).
- A `leave.cancel`-on-behalf-of capability (mirroring `policies.assign`
  for policy acknowledgement), if a real need for HR-initiated
  cancellation emerges.

## Manager Hierarchy

Foundation for every future manager-scoped capability (leave approval,
performance/probation reviews, onboarding tasks, team dashboards, org
chart) — see [`api.md`](api.md#manager-hierarchy) and
[`database.md`](database.md#employees) for the endpoint/schema
reference. Reuses `employees.manager_employee_id` (Checkpoint 6); no
schema change this checkpoint.

### Permission mapping (as seeded in `RoleSeeder`)

| Role | Permissions |
|---|---|
| Tenant Admin | Both (automatic) |
| HR Manager | Both — `employees.view_team`, `employees.update_manager` |
| HR Officer | `employees.view_team` only — **not** `employees.update_manager`, narrower default until a real need is shown, same reasoning already applied to withholding `employees.link_user` from HR Officer in Checkpoint 11 |
| Line Manager | `employees.view_team` only — see below |
| Employee | None |
| Auditor | `employees.view_team` |

### Manager assignment is a structurally closed-off write path

`manager_employee_id` is no longer a validated field on
`StoreEmployeeRequest` or `UpdateEmployeeRequest` — removed entirely,
not patched in place. Every manager assignment/removal goes through
`PATCH`/`DELETE /employees/{employee}/manager`
(`EmployeeManagerController`), gated by `employees.update_manager`,
which runs the full check via `AssignManagerRequest` +
`ManagerHierarchyService`:

1. Tenant match (the prospective manager must belong to the same
   tenant) — `Rule::exists()` scoped to `tenant_id`.
2. **Only `active`-status employees may be assigned as manager** —
   excludes `draft`/`inactive`/`terminated`. The strictest safe default;
   matches the existing "terminated employee cannot be linked" rule from
   `LinkEmployeeUserRequest` (Checkpoint 11).
3. Soft-deleted employees excluded — explicit
   `where('status','active')->whereNull('deleted_at')` closure on the
   `Rule::exists()` check, the same fix already required for
   `document_category_id` (Checkpoint 9) and `leave_type_id`
   (Checkpoint 12); a raw `Rule::exists()` bypasses `SoftDeletes`
   entirely.
4. Self-assignment rejected with a specific error message.
5. Cycle detection (direct and indirect) via
   `ManagerHierarchyService::wouldCreateCycle()`.

Confirmed directly: `PATCH /employees/{employee}` (the general update
endpoint) with `manager_employee_id` in the body returns `200` but
leaves the employee's actual manager unchanged — the field is silently
ignored, not honored and not rejected, the same "not a validated field"
posture used for `tenant_id`/`user_id` elsewhere in this app.

### Fail-closed cycle detection (Refinement 2)

`wouldCreateCycle()` doesn't only return `true` for an actual cycle —
it fails closed (also returns `true`, blocking the assignment) if the
chain above the prospective manager is untrustworthy for *any* reason:

- A chain deeper than `MAX_CHAIN_WALK` (100 hops) — a real org should
  never approach this; hitting it means the existing chain is already
  corrupted.
- A repeated employee ID anywhere in the walk — the chain is already
  cyclic before this assignment even happens.
- A manager belonging to a **different tenant** — shouldn't be
  reachable through normal use (every assignment is tenant-checked at
  write time), but the walk verifies it directly rather than assuming
  it structurally can't happen.
- A **soft-deleted** employee anywhere in the chain.
- A **non-`active`** employee anywhere in the chain.

The walk uses `Employee::withoutGlobalScopes()` deliberately — a normal
(scoped) query would silently exclude a cross-tenant or soft-deleted
employee from the result, making the chain walk terminate early and
incorrectly conclude "no cycle, no problem" instead of surfacing the
untrustworthy state. Bypassing the scopes for this internal safety check
is what makes the fail-closed guarantee real rather than theoretical.

### Two different depth caps — not the same number, for different reasons

- `ManagerHierarchyService::MAX_CHAIN_WALK` (100) — corruption/infinite-
  loop safety net for the cycle-detection *write path*.
- `EmployeeHierarchyController::DEFAULT_REPORTING_TREE_DEPTH` (5) — a
  named constant (not a magic number), response-size cap for the
  `reporting-tree` **display** endpoint. A real org can legitimately be
  deeper than 5 levels; hitting the cap sets `reports_truncated: true`
  on that node rather than silently dropping data with no indication.

### Why Line Manager still doesn't get leave approval this checkpoint (Refinement 6)

**This checkpoint does not change leave approval permissions.** Line
Manager receives `employees.view_team` only — no `leave.approve`, no
`leave.reject`. `LeaveRequestController::approve()`/`reject()`
(Checkpoint 12) are still tenant-wide for any holder of those
permissions, with no manager-hierarchy scoping. Granting them to Line
Manager now, even with `ManagerHierarchyService` available, would still
let any Line Manager approve any employee's leave company-wide, because
nothing in `LeaveRequestController` calls `isManagerOf()` yet. That
wiring — scoping `approve()`/`reject()` by
`ManagerHierarchyService::isManagerOf($actingManager, $requestOwner)` —
is explicitly a **future checkpoint's** work, not this one's. This is
the third occurrence of the same shape of decision (Checkpoint 10:
Employee/`policies.acknowledge`; Checkpoint 12: Line Manager/
`leave.approve`; now this checkpoint declining to change that decision
even though the hierarchy foundation now technically exists) — see
`architecture.md` for why this is worth treating as a standing pattern.

### Direct reports and reporting tree

- `GET /me/direct-reports` — **no permission required**, self-service.
  Scoped exclusively to `$request->user()->employee`'s own direct
  reports; there is no way to pass another employee's ID through this
  endpoint. A caller with no linked employee gets an **empty list**
  (`200`), not a `404` — a list endpoint's natural "nothing here" state,
  consistent with `LeaveRequestController::index()`'s precedent from
  Checkpoint 12 (unlike the single-resource `/me/employee`, which `404`s
  when unlinked).
- `GET /employees/{employee}/direct-reports` — admin/HR view, requires
  `employees.view_team`, one level only (not recursive).
- `GET /employees/{employee}/reporting-tree` — also requires
  `employees.view_team` (Refinement 5). Recursive, depth-capped at
  `EmployeeHierarchyController::DEFAULT_REPORTING_TREE_DEPTH` (5
  levels), eager-loaded one level at a time to avoid an unbounded N+1
  query pattern.
- Both list-shaped endpoints reuse `EmployeeResource`, so sensitive-field
  masking (`personal_email`/`phone` gated by `employees.view_sensitive`)
  applies automatically — no separate masking logic needed.

### Manager assignment audit events (Refinement 4)

`employee.manager_assigned` (first-time assignment, old value was
`null`), `employee.manager_changed` (had a manager, now a different
one), `employee.manager_removed`. Metadata is deliberately narrow —
**IDs only**, no names/emails/phone numbers:

```json
{
  "employee_id": "01h...",
  "old_manager_employee_id": "01h...",
  "new_manager_employee_id": "01h...",
  "tenant_id": "01h..."
}
```

`actor_user_id` is recorded via the standard `AuditLogger::logFor()`
actor parameter, not duplicated into `metadata`. Tested directly,
including an explicit assertion that neither employee's name appears
anywhere in the logged metadata.

### A pre-existing status-code nuance, not a Checkpoint 13 regression

Confirmed directly while smoke-testing: a route with a bound
`{employee}` route parameter (e.g. `GET /employees/{employee}/direct-
reports`) returns `404`, not `403`, when an authenticated session from
tenant A hits tenant B's subdomain with a valid tenant-A employee ID in
the URL. This is because Laravel's implicit route-model-binding
(`SubstituteBindings`, prioritized to run early in the middleware
pipeline) resolves `{employee}` through `BelongsToTenant`'s global scope
*before* `tenant.matches` gets a chance to run its own `403` check — the
model simply isn't found under the wrong resolved tenant, so binding
itself throws first. Confirmed this already held for the pre-existing
`GET /employees/{employee}` route (Checkpoint 6), so this is long-
standing, consistent behavior across every `{model}`-bound route in the
app, not something introduced here. Routes *without* a bound tenant-
scoped model in the URL (`/me/employee`, `/me/direct-reports`, list/
index endpoints) correctly return `403` via `tenant.matches` itself —
see `TenantMatchingMiddlewareTest` for the covered case. Either way the
request is blocked; only the status code differs by route shape. Worth
knowing when writing new tests: assert `404` for `{model}`-bound routes,
`403` for parameter-free ones, when testing cross-tenant session reuse.

### Current limitations

- **`reporting-tree` is depth-capped at 5 levels** — a real org deeper
  than that gets `reports_truncated: true` at the cap, not full data. No
  pagination/lazy-loading of the remainder exists yet.
- **No org chart UI** — API-only, same as every module so far.
- **Leave approval is not yet manager-hierarchy-scoped** — see above;
  `LeaveRequestController` is unchanged this checkpoint.
- **No manager self-service dashboard** — `/me/direct-reports` and
  `/employees/{employee}/reporting-tree` are the only manager-facing
  reads; no aggregate dashboard/summary view exists.
- **No performance/probation review usage yet** — `ManagerHierarchyService`
  is built to be reusable for these, but no such module exists yet.

### Future

- Manager-hierarchy-scoped leave approval (`LeaveRequestController::approve()`/
  `reject()` calling `ManagerHierarchyService::isManagerOf()`) — the
  explicit next step this checkpoint sets up but doesn't build.
- Org chart (frontend + possibly a wider/paginated reporting-tree API).
- Manager self-service dashboard (team summary, pending approvals across
  modules once they exist).
- Performance review and probation review usage of
  `ManagerHierarchyService::isManagerOf()`/`directReportsOf()`.
- Onboarding task assignment scoped to a new hire's manager.
- Pagination/lazy-loading for `reporting-tree` beyond the depth cap.

## Leave Balances Foundation

Adds annual entitlement tracking so leave requests can be capped — see
[`architecture.md`](architecture.md#leave-balances-foundation) and
[`api.md`](api.md#leave-balances) for the design rationale and endpoint
reference. No new leave-request endpoints; this layers enforcement onto
the existing `submit()`/`approve()`/`reject()`/`cancel()` actions from
Checkpoint 12.

### Balance formula

```
available_days = entitlement_days + carried_forward_days + adjustment_days - used_days - pending_days
```

Computed on read (`LeaveBalance::availableDays()`), never stored, per
your explicit instruction — avoids the exact "stale denormalized value"
class of bug this app has otherwise been careful about (see
`LeaveRequest::total_days` always being server-computed, never trusted
from a cache or client input).

### Balance year rule and the cross-year limitation

The balance year is the leave request's `start_date` year — a request
from `2027-08-10` to `2027-08-12` affects the `2027` balance row.
**Cross-year leave requests are rejected outright** (`422`, Option A, as
recommended) — `StoreLeaveRequestRequest`/`UpdateLeaveRequestRequest`
both reject `start_date`/`end_date` falling in different calendar
years. Splitting a single request's days across two different
`leave_balances` rows isn't built; rejecting the request up front avoids
having to guess an allocation.

### Balance-controlled vs. unlimited leave types

`leave_types.max_days_per_year` (Checkpoint 12 column, unused until now)
is the switch:

- **Set** → the leave type is balance-controlled. The first `submit()`
  against it for a given employee/year auto-creates a `leave_balances`
  row (`entitlement_days = max_days_per_year`), and every subsequent
  `submit()`/`approve()`/`reject()`/`cancel()` enforces/updates it.
- **`null`** → the leave type is **not balance-controlled at all**. No
  balance row is ever created or consulted; `submit()` succeeds
  regardless of how many days are requested. This is a per-leave-type
  opt-in, not a tenant-wide setting — a tenant can have both capped
  types (Annual Leave) and uncapped ones (e.g. Bereavement Leave) side
  by side.

### When balance is reserved/consumed/released — and why cancel() needs to know the prior status

| Leave request event | Balance effect |
|---|---|
| Created (`draft`) | None — a draft never touches balance |
| `submit()` (`draft → pending`) | Checks `available_days >= total_days`; reserves into `pending_days` if so, else `422` (leave request status unchanged) |
| `approve()` (`pending → approved`) | Moves `total_days` from `pending_days` to `used_days` |
| `reject()` (`pending → rejected`) | Releases `total_days` from `pending_days` |
| `cancel()` from `pending` | Releases `total_days` from `pending_days` |
| `cancel()` from `draft` | **None** — see below |
| Cancelling an *approved* request | Not supported (unchanged from Checkpoint 12 — `approved` is a terminal status) |

**`pending_days` is a shared aggregate per (employee, leave type, year)
balance, not a per-request ledger.** An employee can have multiple
pending requests against the same balance simultaneously, each having
added its own `total_days` into the same `pending_days` field. This is
exactly why `cancel()` must check whether the specific request being
cancelled was actually `Pending` (i.e. it went through `submit()` and
contributed to that aggregate) before calling `releasePending()` —
cancelling a `Draft` request and releasing anyway would incorrectly
subtract days from the aggregate that this request never added,
corrupting a *different* pending request's reservation on the same
balance. Tested directly
(`test_cancel_draft_request_does_not_affect_balance`).

### Idempotency against repeated/invalid actions (Refinement 1)

Every balance mutation sits *after* `ensureTransitionAllowed()` in each
controller action — an already-approved request re-submitted to
`approve()` gets `409` before `consumePending()` is ever called, so
balance can't be double-consumed by a retried or duplicate request.
Tested directly: approving an already-`approved` request leaves
`used_days` unchanged (`test_invalid_status_transition_does_not_change_balance`).
This is the same `LeaveRequestStatus::canTransitionTo()` single
enforcement point from Checkpoint 12, now also serving as the balance
mutation's own idempotency guard — not a separate check.

### Balance service verifies leave request state before mutation (Refinement 2)

`LeaveBalanceService::consumePending()` is only ever called from
`approve()`, which itself only runs after `ensureTransitionAllowed()`
confirms the request is `Pending` — so `pending_days` reliably reflects
this request's own `submit()`-time reservation before it's moved to
`used_days`. The service methods themselves don't re-derive "was this
actually pending" (that's the controller's job, already enforced) — they
trust the calling discipline the same way every other internal helper
in this app trusts its caller having already checked tenant ownership.

### Transactions cover balance and leave request status together (Refinement 3)

Every balance-affecting action (`submit()`/`approve()`/`reject()`/
`cancel()`) wraps the balance lookup/lock, the balance mutation, the
leave request's own status update, and both audit log writes in one
`DB::transaction()`. A failure at any point — insufficient balance,
a database error, anything — rolls back the whole thing: the leave
request's status never changes if the balance operation didn't
complete, and vice versa. Tested directly (Refinement 8):
`test_submit_exceeding_available_balance_is_rejected` asserts the leave
request is still `draft` after a rejected submission, not left in a
half-updated state.

### Locking — `lockForUpdate()`, not optimistic retry (concurrency)

`LeaveBalanceService::findOrCreate()` locks the balance row
(`lockForUpdate()`) before it's read for the `available_days >= days`
decision, so two concurrent submits against the same balance serialize
rather than both reading the same stale `available_days` and both
succeeding (the classic overspend bug this pattern prevents). Tested
directly: two draft requests for 3 days each against a 5-day balance —
the first submit succeeds (reserves 3, 2 remain), the second correctly
fails (`422`) rather than also reserving 3 and pushing the balance
negative (`test_balance_reservation_uses_locking_and_prevents_overspend`).

The one unavoidable race — two *first-ever* submits for the same
employee/leave-type/year, before any balance row exists yet to lock —
is handled by letting the partial unique index reject the losing
`INSERT` and re-fetching (now lockable) instead of failing the whole
request. See `LeaveBalanceService::findOrCreate()`.

### Preventing negative balances (Refinement 6)

- **Leave request submission**: `reservePending()` rejects (returns
  `false`, no mutation) if `available_days < requested days` — never
  clamps or allows a negative reservation.
- **Manual admin `PATCH`**: `LeaveBalanceController::update()` computes
  the *prospective* `available_days` from the merged (current +
  proposed) values before saving, and rejects (`422`) any change that
  would make it negative. No override/exception mechanism exists this
  checkpoint — a deliberate, documented choice, not an oversight.

### Permission mapping (as seeded in `RoleSeeder`)

| Role | Permissions |
|---|---|
| Tenant Admin | All (automatic) |
| HR Manager | All — `leave_balances.view`/`create`/`update`/`adjust`/`view_all`, per your explicit suggested mapping |
| HR Officer | All five — same reasoning as HR Officer's broad leave/policy grants elsewhere |
| Employee | None — self-service only via `/me/leave-balances`, no admin balance permission |
| Line Manager | None this checkpoint |
| Auditor | `leave_balances.view`, `leave_balances.view_all` |

### `leave_balances.adjust` gates `adjustment_days` specifically

`leave_balances.update` is the baseline for `PATCH` (covers
`entitlement_days`/`carried_forward_days`); a request body that also
includes `adjustment_days` additionally requires
`leave_balances.adjust`, checked in the controller (route middleware
can't inspect body field presence) — mirrors `policies.archive`
requiring `policies.update` in addition (Checkpoint 10). This gives the
two permissions genuinely distinct meaning: a holder of `update` alone
can correct configuration-level fields; changing the ad-hoc
adjustment ledger is a separately-gated action.

### Employee self-service: `GET /me/leave-balances`

No permission required — scoped exclusively to
`$request->user()->employee`'s own balance rows. A caller with no
linked employee gets an **empty list (`200`)**, the same posture as
`/me/direct-reports` (Checkpoint 13), not `/me/employee`'s `404` — a
list endpoint's natural "nothing to show" state. Never exposes another
employee's or another tenant's balances — enforced by both the explicit
`employee_id` filter and `BelongsToTenant`'s scope.

### Audit events

`leave_balance.created` (manual, via `POST /leave-balances`, **or**
auto-created on first `submit()` against a balance-controlled type —
same action name either way, distinguishable by whether the audit
entry's actor matches an admin request or a leave-request submission
flow), `leave_balance.updated`, `leave_balance.adjusted` (specifically
when `adjustment_days` changes), `leave_balance.pending_reserved`,
`leave_balance.pending_released`, `leave_balance.used_recorded`. The
three workflow-triggered events are logged as a **separate** audit
entry alongside the existing `leave_request.*` lifecycle event already
written for that action — not merged into one entry, so a balance
audit and a leave-request audit can each be searched/reasoned about
independently.

### Audit metadata (Refinement 4)

```json
{
  "leave_balance_id": "01h...",
  "leave_request_id": "01h...",
  "employee_id": "01h...",
  "leave_type_id": "01h...",
  "year": 2027,
  "days": 3.0,
  "old_pending_days": 0.0,
  "new_pending_days": 3.0
}
```

IDs, the year, the day delta, and old/new `pending_days` — never
employee names, `reason`, or `rejection_reason`. Confirmed directly with
real free-text values ("Confidential medical procedure.", "Denied due
to confidential HR matter.") asserted absent from the balance audit
entry's metadata, and confirmed the keys `reason`/`rejection_reason`
don't appear in that metadata at all (they're a `leave_request.*` audit
concern, already masked there per the Checkpoint 12 rule — not
duplicated here).

### Current limitations

- **No accrual engine** — entitlement is a flat annual figure, not
  accumulated monthly/per-pay-period.
- **No carry-forward automation** — `carried_forward_days` is
  admin-editable but nothing computes or applies it at year-end.
- **No half-day leave** — every day-count field supports decimals
  (`decimal(6,2)`) for future readiness, but `LeaveRequest::total_days`
  is still a whole number and no UI/validation for half-days exists.
- **No public holiday calendar** — unchanged from Checkpoint 12;
  `total_days` still counts weekends.
- **No manager team-balance view** — `ManagerHierarchyService` exists
  and could support this, but no endpoint surfaces it yet.
- **Approving a request whose leave type became balance-controlled
  *after* it was submitted (while still balance-controlled=false at
  submit time) can consume balance that was never reserved** — a narrow
  edge case (an admin changes `leave_types.max_days_per_year` from
  `null` to a value between an employee's submit and approve) not
  guarded against this checkpoint. Documented here rather than
  engineered around, given how narrow and unlikely the window is;
  revisit if it proves to matter in practice.

### Future

- Accrual engine (monthly/per-pay-period entitlement accumulation).
- Carry-forward automation (year-end rollover with a configurable cap).
- Half-day leave (schema already `decimal(6,2)`-ready on the balance
  side; `LeaveRequest::total_days` and its validation would need to
  follow).
- Public holiday calendars / business-day calculation.
- Manager team-balance view/dashboard.
- Leave encashment.

## Frontend Security Model

The first frontend this app has (Checkpoint 16: Inertia + React +
TypeScript + Tailwind). See [`architecture.md`](architecture.md#frontend-foundation-inertia--react--typescript)
and [`api.md`](api.md#frontend-routes-inertia) for the design rationale
and route reference.

### The rule, stated once, that governs everything below

**Permission-aware UI is for user experience only. The frontend is
never the source of truth for authorization.** Every backend route,
middleware, policy, tenant-isolation check, and object-level check that
existed before this checkpoint is completely unchanged. `PermissionGate`/
`useCan()` (React) decide what to *render*; they never decide what a
request is *allowed to do*. Hiding a sidebar link, disabling a button, or
not rendering a form does not and cannot substitute for a backend check
— every page route and every API action is independently gated exactly
as it was before a frontend existed.

### What's shared with the frontend (`HandleInertiaRequests::share()`)

| Field | Source | Notes |
|---|---|---|
| `auth.user.id` | `$user->id` | |
| `auth.user.name` | `$user->name` | |
| `auth.user.email` | `$user->email` | |
| `auth.user.is_platform_admin` | `$user->is_platform_admin` | |
| `auth.user.employee_id` | `$user->employee?->id` | `null` if unlinked |
| `auth.user.permissions` | `$user->permissionKeys()` | Flat array of permission key strings — presentation data, see below |
| `tenant.id` / `tenant.name` | The container-bound `Tenant`, or `null` | `null` whenever no tenant is resolved (Platform Super Admin on the base domain) — never fabricated |
| `errors` | Inertia's own default share (validation errors) | Standard Inertia behavior, not custom |

### What's never shared, on purpose

Password hash, remember token, session internals, API tokens, document
storage paths/disk, audit log entries, salary/bank/other sensitive
employee fields, raw role-assignment rows, or any tenant configuration
beyond id/name. `#[Hidden(['password', 'remember_token'])]` on `User`
(existing since Checkpoint 3) already prevents the model itself from
serializing these; `HandleInertiaRequests::share()` additionally never
reads them into the shared payload in the first place — two independent
layers, not one relying on the other. Tested directly
(`test_shared_inertia_props_contain_no_sensitive_fields` asserts none of
`password`/`remember_token`/`salary`/`bank`/`storage_path`/`storage_disk`/
`national_id`/`ssn` appear anywhere in the serialized shared props).

### `permissionKeys()` — presentation data, not a new authorization path

`User::permissionKeys()` (in `HasPermissions`) returns the flat list of
permission key strings a user holds via role or direct grant — fails
closed the same way `hasPermission()` does (inactive user or inactive
tenant → empty list, never a stale one). It exists *only* to drive
`useCan()`/`PermissionGate` on the frontend. It is not itself consulted
by any backend authorization check — `hasPermission()` (queried fresh,
per permission, per request) remains the single enforcement point
everywhere it always has been.

### Platform Super Admin never receives a fake tenant context (Refinement 4)

`tenant` in shared props is `null` whenever `app()->bound(Tenant::class)`
is false — which is exactly the case for a Platform Super Admin on the
base domain (no subdomain, `ResolveTenant` binds nothing). There is no
tenant-switching input anywhere in the shared-props payload or any
frontend component — a Platform Super Admin cannot select a tenant to
view through the UI (no such feature is built), and `tenant` being
`null` is not silently defaulted to some tenant's data. Tested directly
(`test_platform_super_admin_does_not_receive_tenant_context`).

### Login/logout: one endpoint, content-negotiated (Refinements 1/2)

`AuthenticatedSessionController::store()`/`destroy()` branch on
`$request->expectsJson()`:

- **True** (existing `postJson()` tests, any real API client) → the
  exact same JSON response as every checkpoint before this one.
- **False** (a real browser/Inertia form post — Inertia's client never
  sends an `Accept: application/json` header) → a redirect
  (`route('dashboard')` on login, `route('login')` on logout).

This required two small `bootstrap/app.php` changes, both already
flagged in their own comments as temporary JSON-only-era workarounds:

1. `redirectGuestsTo` was `fn () => null` (Checkpoint 7 — no login route
   existed at all, so falling through to Laravel's default `route('login')`
   lookup would fatal). Now `fn () => route('login')`, since a real one
   exists. Only consulted for non-JSON-expecting requests — every
   existing `getJson()`/`postJson()` "unauthenticated" test across the
   API suite is unaffected (`expectsJson()` true → straight to `401`,
   this closure never runs).
2. `shouldRenderJsonWhen` hardcoded JSON for `login`/`logout` regardless
   of what the caller wanted. Now `$request->is('api/*') ||
   $request->expectsJson()` — `api/*` always gets JSON (an API surface,
   full stop); everything else defers to real content negotiation, so a
   validation failure on a genuine Inertia form post redirects back with
   errors (which Inertia's client renders on the form) instead of
   returning a raw JSON body to a browser.

### Login page security (Refinement 8)

- CSRF: Inertia's `useForm()` submits through axios, which Laravel's
  `VerifyCsrfToken` middleware already protects via the standard
  `XSRF-TOKEN` cookie/header exchange — no custom CSRF handling was
  written for this page.
- Validation errors are the exact same generic messages
  `LoginRequest::authenticate()` has always thrown ("These credentials
  do not match our records.") — never revealing whether a given email
  exists, whether the failure was the password vs. the account vs. the
  tenant. No behavior change to `LoginRequest` itself; only *how* the
  error reaches the user changed (redirect-back-with-errors vs. JSON
  body).
- An authenticated user hitting `/login` is redirected to `/dashboard`
  before the page ever renders (`AuthenticatedSessionController::create()`).
- Inactive-user and inactive-tenant checks are unchanged — same
  `LoginRequest::authenticate()` code path, now just reachable from two
  request shapes instead of one.

### A pre-existing test assumption this checkpoint exposed

Three test files (`AuthenticationTest`, `AuditLoggingTest`,
`TenantMatchingMiddlewareTest`) called the login/logout/an ad-hoc
protected route with bare `$this->post()`/`$this->get()` and asserted
`200`/`401` outright. This worked only because *every* response from
those routes was JSON, unconditionally, regardless of what the request
actually asked for — true before this checkpoint, no longer true now
that a real browser flow exists. Fixed by converting the JSON-contract
tests to explicit `postJson()` (matching what they were actually always
testing) and adding new tests for the browser/redirect flow
specifically (`InertiaAuthTest`) — see `docs/testing.md` for the full
writeup; this is not a weakening of any check, just making each test
honestly declare which contract it exercises.

### Route-level "fail closed" for the one page without a permission gate

`/dashboard` has no `permission:{key}` middleware (nothing to gate — it's
the landing page every authenticated user reaches), so it's the one
authenticated route that doesn't already fail closed for inactive
users/tenants via `hasPermission()`'s existing fail-closed behavior.
`DashboardController::index()` checks `$user->isActive()` and tenant
active-status explicitly, aborting `403` otherwise — the same rule
`hasPermission()` already enforces everywhere else, applied directly
since there's no permission check here to piggyback on. Every other
frontend page route (`/employees`, `/leave`, `/documents`, `/policies`,
`/settings`) inherits fail-closed behavior "for free" from its
`permission:` middleware, exactly like their API counterparts.

### Current limitations

- No real analytics/dashboard data — the dashboard is welcome message +
  linked-employee status + a permission count, explicitly not built
  further this checkpoint.
- No Employee/Leave/Document/Policy module UI — `/employees`, `/leave`,
  `/documents`, `/policies`, `/settings` are permission-gated
  placeholders (`EmptyState`), not functional screens.
- No JS/TS unit test runner configured (no Jest/Vitest) — frontend
  verification this checkpoint is: `tsc --noEmit`, `vite build`, and
  backend feature tests asserting the Inertia response shape
  (`assertInertia()`), redirects, and shared-prop safety. See
  `docs/testing.md`.
- `Sidebar.tsx`'s nav list is hardcoded in the component, not derived
  from a backend-provided menu structure — fine for 5 static links,
  worth revisiting if the nav grows data-driven needs (per-tenant
  branding, feature flags, etc.).
- No Ziggy (route-name-to-JS) — plain path strings in React. Revisit if
  hardcoded paths become a real maintenance burden as more pages are
  added.

### Future

- Real module UIs for Employees, Leave, Documents, Policies (each
  reusing the existing `/api/v1` endpoints already built).
- Manager/Reports/Audit nav groups and pages, once they exist.
- A real dashboard with live counts/summaries (still no charts/analytics
  engine planned yet — that's an explicit future decision, not assumed).
- Frontend test tooling (Vitest + React Testing Library) if/when
  component-level testing becomes valuable beyond the current
  backend-response-shape verification.

## Employee Records UI

The first real module screen built on the Checkpoint 16 frontend
foundation — see [`architecture.md`](architecture.md#employee-records-ui-checkpoint-17)
for the client-side-fetching design and [`api.md`](api.md#frontend-routes-inertia)
for the route reference.

### The rule restated, because it matters most here

Every one of the 4 web routes (`/employees`, `/employees/create`,
`/employees/{id}`, `/employees/{id}/edit`) carries the exact same
`permission:{key}` middleware its corresponding `/api/v1/employees`
action already requires. `PermissionGate`/`useCan()` only ever decide
what a React component *renders* (a Create button, an Edit link, a
Delete action, which microcopy to show for a masked field) — they never
decide what a request is *allowed to do*. A user who bypasses the UI
entirely (a direct URL, a modified request, a browser devtools console)
hits the identical backend checks a legitimate UI interaction would.
Confirmed directly in the live smoke test: a user without
`employees.create`/`employees.update` gets `403` from
`/employees/create`/`/employees/{id}/edit` regardless of what the
sidebar shows them.

### Sensitive fields: rendered honestly, never worked around (Refinement 5)

`personal_email`/`phone` come back `null` from `/api/v1/employees/{id}`
both when genuinely empty and when the viewer lacks
`employees.view_sensitive` (`EmployeeResource`'s existing masking logic,
unchanged since Checkpoint 6) — the frontend cannot and does not try to
distinguish these from the value alone. `Show.tsx` uses the viewer's own
`employees.view_sensitive` entry in the already-shared permission list
purely to choose *microcopy* for an already-`null` value: "Not visible"
(lacks the permission) vs. "Not provided" (holds it, genuinely empty).
This is cosmetic, not a security decision — the real value was already
decided server-side before this component ever received the response.
No client-side code anywhere attempts a second request, a different
endpoint, or any other workaround to obtain a masked value.

### Payload allowlisting (Refinement 3) — belt and braces, not the real backstop

Create/Edit forms build their `POST`/`PATCH` payload from
`EmployeeFormPayload` field-by-field, never by spreading a broader
object. This is a second, independent layer behind the backend's own
structural field exclusions (`manager_employee_id`/`user_id`/
`tenant_id`/`created_by`/`updated_by` were already unconditionally
rejected by `Store`/`UpdateEmployeeRequest` since Checkpoints 11/13) —
useful because it means the *form itself* can never accidentally submit
a forbidden field, but the backend remains what actually enforces the
rule. Tested live: a payload deliberately including `tenant_id` and
`manager_employee_id` was still accepted (`201`), both fields silently
dropped, `manager_employee_id` staying `null`.

### Delete is safe by construction (Refinement 4)

The list page's delete action requires `employees.delete` (UI-gated),
confirms via `window.confirm()`, calls `DELETE /api/v1/employees/{id}`,
and only removes the row from the visible list *after* the backend
confirms success (a full refetch, never an optimistic removal
beforehand) — matching the backend's own guarantee (unchanged since
Checkpoint 6) that this endpoint only ever soft-deletes. A `403`/`404`
from the delete call surfaces as the same safe inline error banner used
elsewhere, never a raw response body.

### `lib/api.ts` — the shared error-handling contract

| Backend response | Frontend behavior |
|---|---|
| `401` | Full-page redirect to `/login` (`redirectIfUnauthenticated()`) — no useful in-page state exists once the session is gone |
| `403` | Safe generic message: "You don't have permission to do this." |
| `404` | Safe generic message: "Not found." |
| `422` | Field-level errors mapped onto the form; a general "Please fix the errors below." banner |
| anything else | Generic "Something went wrong. Please try again." — never the raw response body or a stack trace |

### Web route tenant isolation for `show`/`edit` (Refinement 1)

`{employee}` route-model-binding is already scoped by `BelongsToTenant`'s
global scope — a cross-tenant ID never resolves to a model at all, so
Laravel throws `ModelNotFoundException` (a plain `404`) before
`EmployeeUiController::show()`/`edit()` ever runs. Both methods
additionally call `ensureBelongsToCurrentTenant()` as defense in depth,
the same "don't rely solely on the global scope" principle every API
controller in this app already follows — matters here specifically
because these methods never forward employee *data* onward, only the
ID, so there's nothing to leak even in the hypothetical case this check
were bypassed. Confirmed directly: a live cross-tenant session request
to `/employees/{other-tenant-employee-id}` returns `404` (the same
pre-existing status-code nuance documented in Checkpoint 13 — route-
model-binding's tenant scope resolves before `tenant.matches` would
otherwise return `403`).

### What is not, and cannot be, tested by a JS runner (Refinement 7)

No Jest/Vitest is configured (unchanged since Checkpoint 16). The
following are verified through `tsc --noEmit`, `npm run build`, and a
live HTTPS smoke test — **not** automated unit tests, and this is
stated explicitly rather than implied:

- Create/Edit/Delete button visibility based on `useCan()`.
- Client-side rendering of `422` field errors onto form inputs.
- The `403`/`404`/generic error banners actually appearing in the DOM.
- The delete confirmation dialog (`window.confirm()`) itself.

What *is* backend-tested (`EmployeeUiTest`): permission-gating on all 4
routes (`403`/`200`), guest redirects, cross-tenant `404` on `show`/
`edit`, the correct `employeeId` prop, and that shared Inertia props for
these pages carry only the ID — never employee data (so there's no new
sensitive-field-leak surface via Inertia to test; the existing
`EmployeeApiTest` suite already covers the actual masking logic these
pages render).

### Current limitations

- No department/location/position pickers — no listing API exists yet
  for those lookup tables (see `architecture.md`).
- No manager assignment or user-linking UI — both have dedicated backend
  endpoints (Checkpoints 11/13) but no frontend yet; the generic
  edit form deliberately never loads or submits either.
- No employee documents UI.
- No bulk actions, import/export, or advanced search/filtering beyond
  whatever `/api/v1/employees` already supports.
- No JS/TS unit test runner — see above.

### Future

- Manager assignment UI, reusing `PATCH`/`DELETE /employees/{id}/manager`.
- User-linking UI, reusing `POST`/`DELETE /employees/{id}/link-user`/`unlink-user`.
- Employee documents UI, reusing the existing document upload/download/list endpoints.
- Department/location/position pickers, once a listing API exists.
- Frontend test tooling (Vitest + React Testing Library), if component-level testing becomes valuable.

## Leave Management UI

The second real module screen, built on the same Checkpoint 16
foundation and following the same pattern as
[Employee Records UI](#employee-records-ui) above — see
[`architecture.md`](architecture.md#leave-management-ui-checkpoint-18)
for the client-side-fetching design and
[`api.md`](api.md#frontend-routes-inertia) for the route reference.

### The rule restated, again, because it matters just as much here

`/leave` requires `permission:leave.view`, `/leave/create` requires
`permission:leave.request`, `/leave/{id}` requires `permission:leave.view`
— identical to their `/api/v1` counterparts. `PermissionGate`/`useCan()`
only decide whether Submit/Cancel/Approve/Reject *render*; the backend
(`leave.approve`/`leave.reject`, `ensureNotOwnRequestForApprovalAction()`,
`resolveApprovalScope()`, `LeaveBalanceService`, `LeaveRequestStatus::
canTransitionTo()`) remains the sole authority on whether an action is
actually allowed. Confirmed directly in the live smoke test: a Line
Manager's Approve button rendered identically for a direct report's
request and an unrelated employee's request — the backend, not the UI,
was what correctly allowed one (`200`) and rejected the other (`403`).

### The frontend cannot know `ManagerHierarchyService`'s scope — a limitation, not a bug

Unlike every other permission check in this app (which the frontend can
mirror exactly, because `permissionKeys()` gives it the same yes/no
answer the backend would), Approve/Reject visibility genuinely **cannot**
be computed correctly client-side: `resolveApprovalScope()` depends on
`ManagerHierarchyService::directlyManages()`, which needs the full
manager-hierarchy chain — data that isn't (and shouldn't be) shipped to
the browser wholesale. `Show.tsx` renders both buttons whenever the
viewer holds the relevant permission and the request is `pending`,
accepting that some renders will lead to a backend `403`. This is
deliberate, not an oversight: shipping enough hierarchy data to predict
the answer client-side would mean exposing tenant-wide manager/employee
relationship data to every leave-approving user, a materially larger
disclosure than the leave request itself. A resulting `403` is handled
exactly like any other permission failure (generic safe message, no
special-casing) — see `lib/api.ts`'s error contract below.

### Balances are read-only, on purpose (Refinement 2)

`Index.tsx` renders `/me/leave-balances` fields (entitlement, used,
pending, carried forward, adjustment, available) as plain text — no
input, no edit affordance, no admin adjustment UI anywhere in this
checkpoint. `LeaveBalanceService`'s enforcement (`reservePending()`/
`consumePending()`/`releasePending()`, `lockForUpdate()`) is entirely
backend-side and untouched; the frontend only ever displays the result
of a `GET`, never attempts to influence it.

### Rejection reason: required client-side, masked same as everywhere else (Refinement 5)

`RejectReasonPrompt` won't call `onConfirm()` without non-empty text, and
the reject request body contains **only** `rejection_reason` — no other
field is sent alongside a reject action. This is a client-side
convenience (avoids a round-trip for an empty reason the backend would
reject anyway via `RejectLeaveRequestRequest`'s validation) — the actual
requirement is enforced by that same backend validation, unchanged since
Checkpoint 12. The reason text is never rendered anywhere in the UI
outside the reject flow itself (no audit/history view surfaces it), and
a `422` from a failed reject shows the field-level message only — never
a raw backend trace.

### Employee-ID display: subtle by design, not a finished HR-facing pattern (Refinement 1)

`LeaveRequestResource` has no employee *name* field, only `employee_id`.
`resources/js/lib/format.ts`'s `formatEmployeeRef()` renders `"You"` for
the viewer's own request, or `` `Employee record (ID ending •••1234)` ``
otherwise — deliberately truncated and labeled so it reads as a
provisional placeholder, not a finished design decision. This is the
same problem Checkpoint 17 solved by *omitting* department/location
fields entirely; here the ID is too useful (the only way to distinguish
rows in a multi-employee list) to omit, so it's shown, but shown
unobtrusively. See [`architecture.md`](architecture.md#leave-management-ui-checkpoint-18)
for the full rationale — this goes away once a real employee-name
lookup exists on the leave API.

### Safe status-action handling (Refinement 4)

Every Submit/Cancel/Approve/Reject action in `Show.tsx` goes through one
`runAction()` helper: button disabled while `processing`, no optimistic
status update before the backend confirms success, a full `load()`
refetch after success, and specific handling for `403` (generic
message), `409` ("This request can no longer be changed." — the
tightened default in `lib/api.ts`, see below), and `422` (field
errors) — never a raw error body rendered to the user.

### `lib/api.ts`'s error contract — unchanged from Checkpoint 17 except one message

| Backend response | Frontend behavior |
|---|---|
| `401` | Full-page redirect to `/login` |
| `403` | "You don't have permission to do this." |
| `404` | "Not found." |
| `409` | Backend's own message if present, else "This request can no longer be changed." (tightened this checkpoint — was the more generic "This action conflicts with the current state." in Checkpoint 17; leave status-transition conflicts are common enough in normal use, via double-submission or a stale tab, to warrant a more specific default) |
| `422` | Field-level errors + "Please fix the errors below." |
| anything else | Generic "Something went wrong. Please try again." |

### Web route tenant isolation for `show` (same pattern as Checkpoint 17's Refinement 1)

`{leaveRequest}` route-model-binding is already scoped by
`BelongsToTenant`; `LeaveUiController::show()` additionally calls
`ensureBelongsToCurrentTenant()` as defense in depth, same reasoning as
`EmployeeUiController`. Confirmed directly in the live smoke test and in
`LeaveUiTest::test_show_page_returns_404_for_cross_tenant_leave_request`
— a cross-tenant leave request ID returns `404`, not `403` (the same
route-model-binding-resolves-before-`tenant.matches` nuance documented
under Checkpoint 13).

### What is not, and cannot be, tested by a JS runner (Refinement 7)

Same posture as Checkpoint 17 — no Jest/Vitest configured. Verified via
`tsc --noEmit`, `npm run build`, and a live HTTPS smoke test, not
automated unit tests:

- Submit/Cancel/Approve/Reject button visibility based on `useCan()`
  and request status.
- The reject-reason prompt's required-field behavior.
- Client-side `422`/`403`/`409` error banners actually appearing in the DOM.
- The inline balance cards' read-only rendering.

**The manager-scope approval flow specifically was live-tested, not just
documented as backend-covered** (Refinement 7 asked for this "if
practical" — it was practical): a Line Manager viewing/approving a direct
report's request succeeded end-to-end through the real UI, and the same
Line Manager was correctly blocked (`404` on view, `403` on approve) from
an unrelated employee's request. The full manager-hierarchy authorization
*logic* itself (cycle detection, chain depth, cross-tenant/inactive
managers) remains covered by `ManagerHierarchyServiceTest`/
`ManagerScopedLeaveApprovalTest` from Checkpoints 13/14 — this
checkpoint didn't re-test that logic, only that the UI correctly reaches
it.

What *is* backend-tested (`LeaveUiTest`): permission-gating on all 3
routes, guest redirects, cross-tenant `404` on `show`, the correct
`leaveRequestId` prop, and that shared Inertia props carry only the ID —
confirmed directly with a real confidential `reason` string absent from
the serialized props.

### Current limitations

- No leave balance admin UI — creation/adjustment of `leave_balances`
  rows remains API/tinker-only, per Refinement 2.
- No leave type admin UI — `leave_types` management remains API-only.
- No calendar view, team-leave overview, or notification integration.
- No accrual-engine UI — matches the backend, which has none yet either
  (see [Leave Balances Foundation](#leave-balances-foundation)).
- No workflow builder or configurable approval chains — manager-scope
  approval is the fixed, built-in rule from Checkpoint 14.
- No JS/TS unit test runner — see above.

### Future

- Leave balance and leave type admin UIs, once those become a real need.
- A team/manager leave calendar view, reusing `leave.view_team`.
- Notification integration (email/in-app) on submit/approve/reject.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Document Repository UI

The third real module screen, built on the same Checkpoint 16
foundation — see
[`architecture.md`](architecture.md#document-repository-ui-checkpoint-19)
for the employee-scoped design rationale and
[`api.md`](api.md#frontend-routes-inertia) for the route reference.

### The rule restated a third time, because it's the whole security model

`/employees/{employee}/documents` and `/employees/{employee}/documents/{document}`
require `permission:documents.view`; `/employees/{employee}/documents/upload`
requires `permission:documents.upload` — identical to their `/api/v1`
counterparts. `PermissionGate`/`useCan()` only ever decide whether
Upload/Download/Delete *render*. Every backend check from Checkpoint 8
(`ensureEmployeeBelongsToCurrentTenant()`, `ensureDocumentBelongsToEmployee()`,
sensitive-document exclusion, private-disk storage, `documents.delete`)
is completely unchanged and remains the sole authority. Confirmed
directly in the live smoke test: an HR Manager's Delete button (which
the frontend correctly never renders, since HR Manager doesn't hold
`documents.delete` — see the Role mapping table under
[Employee Records](#employee-records) — only Tenant Admin does) was also
independently rejected (`403`) when the underlying API call was issued
directly, confirming the UI's hidden button and the backend's real
enforcement agree, rather than one silently relying on the other.

### A pre-existing permission gap, closed narrowly (the plan's Refinement 1)

`GET /api/v1/document-categories` requires `document_categories.view` —
seeded, before this checkpoint, only to Tenant Admin. HR Manager and
Employee (the only two roles holding `documents.upload`) would have hit
a `403` fetching the very category list their own upload form depends
on. Fixed in `RoleSeeder` by granting **only** `document_categories.view`
to both roles — explicitly **not** `document_categories.create`/
`update`/`delete`, which remain Tenant-Admin-only. This is a narrow,
additive, read-only grant: seeing what categories exist (needed to
upload correctly — sensitivity indicator, expiry-date requirement) is a
materially lower trust level than being able to create, rename, or
retire a category tenant-wide. Confirmed directly in the live smoke
test: HR Manager's `GET /api/v1/document-categories` call, which would
have `403`'d before this checkpoint, now returns `200`.

### Category dropdown filtering and its safe-failure default (Refinement 2)

The upload form's category dropdown shows only `status: active`
categories, preferring `applies_to: employee` ones; if none of those
exist, it falls back to any active category rather than leaving the
dropdown confusingly empty. If the category fetch itself fails (e.g. a
future role holds `documents.upload` without `document_categories.view`),
the upload form is replaced entirely by a blocking error message — it
does **not** silently fall back to letting the user upload an
uncategorised document, per your explicit instruction not to choose that
fallback silently. This matters because the sensitivity-indicator and
expiry-date-requirement checks below both depend on the category list
having loaded successfully; proceeding without it would silently skip
those safeguards rather than surfacing that they're unavailable.

### Expiry-date and sensitivity indicators are cosmetic, not authoritative (Refinements 3/4)

If the selected category has `requires_expiry_date: true`, the expiry
date input becomes client-side `required` and submission is blocked
client-side without one — purely a UX nicety; `StoreEmployeeDocumentRequest::
withValidator()` (Checkpoint 8, unchanged) independently rejects a
missing expiry date for such a category regardless of what the client
did or didn't check. If the selected category has `is_sensitive: true`,
an inline warning ("This document category is marked as sensitive.
Access will be restricted.") is shown — again cosmetic; the actual
restriction (`documents.view_sensitive`-gated exclusion from listings,
Checkpoint 8) happens entirely server-side, driven by the category's
real `is_sensitive` value, not by anything the browser decided to
display.

### Download: a new helper, because the JSON error contract doesn't apply to binary responses (Refinement 5)

`lib/download.ts`'s `downloadEmployeeDocument()` calls the existing
authenticated download endpoint through `api` (the same
`withCredentials` axios instance every other request uses) with
`responseType: 'blob'`, creates an object URL only after a genuine `2xx`
response, triggers the download via a temporary anchor element, and
revokes the object URL immediately after. Deliberately **not** a plain
`window.location = downloadUrl` navigation or a raw `<a href="...">` to
the API — two reasons: (1) that would bypass `toApiError()`'s handling
entirely, so a `403`/`404` response would either fail silently or (2)
worse, some browsers would happily save the raw JSON error body to disk
as if it were the requested file, named after the document. The helper
re-parses a failed blob response's body as text/JSON before handing it
to `toApiError()` for exactly this reason — a `Blob` isn't something
`toApiError()` can read `.message`/`.errors` off directly. Confirmed
live: a `403` download attempt (a user holding `documents.view` but not
`documents.download`) surfaced the normal safe generic message, not a
downloaded error-body file.

### Delete/archive: same safe pattern as Employee Records and Leave (Refinement 6)

Requires `documents.delete` (UI-gated), confirms via `window.confirm()`,
calls the existing `DELETE` endpoint, and only navigates away/refetches
*after* the backend confirms success — never an optimistic removal
beforehand. A `403`/`404` from the delete call surfaces as the same safe
inline error banner used elsewhere. Confirmed live: after a successful
delete, both the API `show` endpoint and the web detail page correctly
return `404` (the document is gone via `SoftDeletes`' global scope), not
some stale cached state.

### Object-level checks: the same-tenant-wrong-employee case, tested explicitly (Refinement 7)

`EmployeeDocumentUiController::show()` performs the same two-layer check
`EmployeeDocumentController::show()` already does at the API layer
(Checkpoint 8): `ensureEmployeeBelongsToCurrentTenant()` *and*
`ensureDocumentBelongsToEmployee()`. The second check catches a
genuinely different failure mode than tenant isolation — a `document_id`
that's entirely valid *for the current tenant*, just for a *different
employee* than the one in the URL. Both the route-model-binding's
`BelongsToTenant` scope and a plain cross-tenant check would let this
through; only the explicit ownership check catches it. Tested directly
(`EmployeeDocumentUiTest::test_same_tenant_wrong_employee_document_returns_404`)
and confirmed live.

### No document data or private storage paths ever reach the frontend as props

`EmployeeDocumentUiController`'s three methods pass only `employeeId`
and (on `show()`) `documentId` — never document data, and certainly
never `storage_path`/`storage_disk`/`stored_filename` (which
`EmployeeDocumentResource` never returns to *any* consumer in the first
place, unchanged since Checkpoint 8). Tested directly
(`EmployeeDocumentUiTest::test_show_page_props_contain_only_ids_not_document_data`
seeds a document with a deliberately identifiable title and a
recognisable fake storage path, then asserts neither string appears
anywhere in the serialized page props).

### No file preview (Refinement 9)

The detail page shows metadata only — title, description, category,
original filename, MIME type, file size, status, dates, sensitivity
indicator, and Download/Delete actions. No inline preview, no embedded
viewer, no thumbnail generation — deliberately out of scope this
checkpoint, per your explicit "do not build" instruction.

### What is not, and cannot be, tested by a JS runner

Same posture as Checkpoints 17/18 — no Jest/Vitest configured. Verified
via `tsc --noEmit`, `npm run build`, and a live HTTPS smoke test, not
automated unit tests:

- Upload/Download/Delete button visibility based on `useCan()`.
- The category dropdown's active/employee-scoped filtering and its
  sensitivity/expiry-requirement UI hints.
- The file picker and client-side expiry-date-required validation.
- The actual blob-download-and-save browser behavior.
- The delete confirmation dialog (`window.confirm()`).

What *is* backend-tested (`EmployeeDocumentUiTest`, 12 tests): guest
redirects, permission gating on all 3 routes, cross-tenant `404` on
employee ID, cross-tenant `404` on document ID, the same-tenant-wrong-
employee `404` (Refinement 7), and that shared Inertia props for both
the list and detail pages carry only IDs.

### Current limitations

- No tenant-wide document centre — see
  [`architecture.md`](architecture.md#document-repository-ui-checkpoint-19)
  for why (no tenant-wide listing endpoint exists yet to build one on).
- No document approval workflow UI — `documents.approve` permission and
  `approved_by`/`approved_at` fields remain reserved, unused (same
  backend limitation as Checkpoint 8).
- No eSignature, document generation, OCR, malware-scanning UI, or
  cloud/S3 UI — matches the backend, which has none of these either.
- No bulk upload, folder management, or advanced search/filtering.
- No file preview — see Refinement 9 above.
- No policy-document integration UI — Policy Management's own document
  linking (`policy_versions.employee_document_id`, Checkpoint 10) has no
  frontend yet.
- No JS/TS unit test runner — see above.

### Future

- A tenant-wide document centre, once a tenant-wide listing endpoint
  exists to build it on safely.
- Policy document integration UI, reusing `policy_versions.employee_document_id`.
- A document approval workflow UI, reusing the reserved `documents.approve`
  permission and `approved_by`/`approved_at` fields.
- eSignature and document generation — explicitly out of scope until a
  real backend capability exists for either.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Policy Management UI

The fourth real module screen, built on the same Checkpoint 16
foundation — see
[`architecture.md`](architecture.md#policy-management-ui-checkpoint-20)
for the missing-versions-endpoint gap this checkpoint closed and
[`api.md`](api.md#policy-management) for the route reference.

### The rule restated a fourth time

Every `{policy}`-bound route (`/policies/{id}`, `/edit`,
`/versions/create`, `/assign`, `/acknowledgements`) carries the same
`permission:{key}` middleware its `/api/v1` counterpart already
requires. `PermissionGate`/`useCan()` only decide whether Create
version/Publish/Assign/Acknowledge/View acknowledgements *render* — the
backend (`PolicyController`'s permission checks, the self-approval-style
acknowledgement resolution, the draft-only `policy_version_id` scoping
on publish, the tenant/policy-ownership checks) remains the sole
authority, completely unchanged from Checkpoint 10.

### The new `GET /api/v1/policies/{policy}/versions` endpoint

Approved before implementation (see the checkpoint transcript) as a
narrow, read-only exception to "use existing endpoints only" — the UI
could not show current-version content or offer a safe draft-version
picker for publishing without it. Design constraints, all satisfied:

- **Gated by `policies.view`** — no new permission; the same trust level
  as viewing the policy itself, since this is read-only reference data
  a viewer of the policy should already be able to see.
- **Scoped through `$policy->versions()`, not a free query by
  `policy_id`** — a version belonging to a *different* policy, even in
  the same tenant, structurally cannot appear in this endpoint's
  response. Tested directly
  (`PolicyApiTest::test_versions_endpoint_only_returns_versions_for_the_requested_policy`).
- **Tenant-scoped the same way every other Policy endpoint is** —
  `ensureBelongsToCurrentTenant()` first; `PolicyVersion` already uses
  `BelongsToTenant`. Tested directly
  (`test_tenant_a_cannot_list_tenant_b_policy_versions`).
- **No new write path** — `GET` only; `POST .../versions` (create) and
  `POST .../publish` are completely unchanged.
- Full `auth`/`tenant.matches`/active-user/active-tenant enforcement is
  identical to every other Policy route (same middleware group, same
  `hasPermission()` fail-closed behavior) — nothing new to add here,
  since it's registered inside the same `Route::middleware([...])->group()`
  as the rest of `/api/v1/policies/*`.

### Publish: never a guessed or empty version ID (Refinements 2/3)

`Policies/Show.tsx` fetches the versions list and filters to
`status: draft` client-side. With zero drafts, no publish control is
shown at all beyond a plain "No draft versions available to publish"
message — there is no code path in this UI that could submit `POST
.../publish` with a missing or fabricated `policy_version_id`. With one
or more drafts, the user explicitly selects one from a `<select>``
populated only with IDs the versions endpoint actually returned for
*this* policy. Publishing itself: confirmation prompt, disabled button
while processing, no optimistic status update, and both the policy and
versions list are refetched only after the backend confirms success. A
`422` from `PublishPolicyRequest` (e.g. selecting a version with no
content) surfaces its specific field message rather than a generic one.

### Assignment: allowlisted, and blocked client-side before it would even 422 (Refinement 4)

`Policies/Assign.tsx` sends exactly `employee_ids` (an array built from
checkbox selections against the fetched `/api/v1/employees` list — never
free text) and an optional `due_date`. No `tenant_id`, `policy_id` (it's
a route parameter, never a form field), `policy_version_id` (not
required by `AssignPolicyRequest` — assignment always targets the
policy's own `current_version_id` automatically), `assigned_by`,
`assigned_at`, or `acknowledgement_status` are ever fields on this form.
If the policy has no `current_version_id`, the assign form isn't
rendered at all — a plain message explains why — mirroring
`PolicyController::assign()`'s own `abort_unless($policy->current_version_id, 422, ...)`
rule as a UI convenience, not a replacement for it.

### Acknowledgement: self-scoped only, by omission (Refinement 5)

The Acknowledge button calls `POST /policies/{policy}/acknowledge` with
an **empty body** — `employee_id` is never a field anywhere in this
checkpoint's UI. This means the frontend only ever exercises
`PolicyController::acknowledge()`'s self-acknowledgement branch (employee
resolved from the caller's own linked employee, Checkpoint 11); the
admin-recorded-on-behalf-of-someone-else branch still exists and remains
tested at the API layer, it simply has no UI entry point built this
checkpoint. Confirmed live: `employee@uesl.peopleos.test` acknowledging
their own assigned policy recorded `acknowledgement_method: "web"` (the
self-service method), not `admin_recorded`.

### Acknowledgement list: no raw IDs, no technical metadata (Refinement 6)

`Policies/Acknowledgements.tsx` reuses `formatEmployeeRef()`
(Checkpoint 18) for the employee column — `PolicyAcknowledgementResource`
has no employee name field, so this renders "You" for the viewer's own
record or a truncated, visibly provisional placeholder otherwise, same
reasoning as Leave/Documents. `ip_address`, `user_agent`, `assigned_by`,
and every other raw internal-actor field the API happens to return are
deliberately never rendered on this page — not because they're masked
server-side (they aren't; `PolicyAcknowledgementResource` returns them
plainly, same as always), but because this screen has no legitimate use
for them and displaying them would be unnecessary technical/personal
data exposure beyond what the spec asked this screen to show.

### No content-injection risk from policy version text (Refinement 9)

`content`/`summary` are rendered via plain JSX text interpolation
(`{content}`), never `dangerouslySetInnerHTML` — React escapes text
children automatically, so this is safe by construction regardless of
what a version's content contains. No rich-text editor, WYSIWYG toolbar,
or Markdown renderer was added this checkpoint — content-only,
plain-text versions, per your explicit instruction.

### `owner_user_id` and `employee_document_id`: backend-safe, UI-omitted

Both fields are validated safely server-side
(`owner_user_id` via a tenant-scoped `Rule::exists('users', ...)`;
`employee_document_id` via a tenant-scoped `Rule::exists('employee_documents', ...)`)
but neither appears on the Create/Edit/Version-create forms:
`owner_user_id` has no safe lookup UI to build on (no `/api/v1/users`
listing endpoint exists at all this checkpoint), and
`employee_document_id` has no safe general/policy-scoped document picker
(only employee-scoped documents exist, Checkpoint 8/19 — attaching one
to a tenant-wide policy is the same semantic mismatch flagged in
Checkpoint 10's Policy Management section). Omitted rather than built
as an unsafe raw-ID text input, per your explicit instruction.

### A pre-existing note worth flagging, not fixed this checkpoint

`UpdatePolicyRequest` technically accepts a `status` field beyond just
`archived` (only the `archived` transition is additionally gated by
`policies.archive` inside the controller) — meaning `PATCH
/policies/{policy}` could in principle set `status: published` directly,
bypassing `publish()`'s own invariants (a version must exist, have
content, be a draft). This is a pre-existing characteristic of the
Checkpoint 10 API, not something introduced or changed this checkpoint.
The Edit form (`Policies/Edit.tsx`) deliberately never includes a
`status` field at all, so this UI cannot trigger that path — but the
API endpoint itself remains as permissive as it always was. Flagged here
for future attention rather than silently worked around or silently
ignored.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. Verified via `tsc --noEmit`, `npm run build`, and a live
HTTPS smoke test:

- Create/Edit/Publish/Assign/Acknowledge/View-acknowledgements button
  visibility based on `useCan()`.
- The draft-version `<select>` and its "no drafts available" fallback.
- The publish confirmation prompt.
- The employee multi-select checkboxes on the Assign page.
- The Acknowledge button's success/error message rendering.

What *is* backend-tested (`PolicyUiTest`, 18 tests, plus 4 new tests in
`PolicyApiTest` for the versions endpoint): guest redirects on all 7
routes, permission gating both directions on every route, cross-tenant
`404` on every `{policy}`-bound route, and that shared Inertia props
carry only `policyId` — confirmed directly with a real policy title
("Confidential Disciplinary Procedure") asserted absent from every
bound page's serialized props.

### Current limitations

- No policy campaign automation, email reminders, or escalations for
  overdue acknowledgements.
- No policy dashboard or compliance reporting.
- No policy template library.
- No bulk or department/location-wide assignment — the assign form's
  employee selector shows only the first page of `/api/v1/employees`
  (no search/pagination in the selector this checkpoint).
- No admin-recorded-on-behalf-of-employee acknowledgement UI — the API
  path exists and is tested, this checkpoint's UI only calls the
  self-acknowledgement path.
- No `owner_user_id` or `employee_document_id` picker — see above.
- No rich text editor — plain text content only.
- No JS/TS unit test runner — see above.

### Future

- A policy dashboard and compliance/acknowledgement reporting.
- Email reminders and escalations for overdue acknowledgements.
- Policy campaign automation (scheduled assignment on a cadence).
- Document integration UI, once a safe general/policy-scoped document
  picker exists (reusing `policy_versions.employee_document_id`).
- A user picker for `owner_user_id`, once a safe `/api/v1/users` listing
  endpoint exists.
- An admin-recorded-on-behalf-of-employee acknowledgement UI.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Dashboard Foundation

Replaces the Checkpoint 16 placeholder dashboard with real,
permission-aware module summaries — see
[`architecture.md`](architecture.md#dashboard-foundation-checkpoint-21)
for the "aggregate endpoint, not a listing endpoint" design and
[`api.md`](api.md#dashboard) for the response shape.

### The rule, stated exactly once, that this entire feature exists to satisfy

**`dashboard.view` grants reaching `/dashboard`/`GET /api/v1/dashboard`.
It grants nothing else.** Every card in the response is independently
gated by the same module permission its real page/endpoint already
requires — a user holding `dashboard.view` and nothing else gets a
`200` with empty `cards`/`recent_items` arrays, never an error and
never a card it hasn't earned. This is checked twice, on purpose:

1. **Route-level**, for the API: `permission:dashboard.view` middleware
   on `GET /api/v1/dashboard` — this alone blocks anyone who can't reach
   the endpoint at all.
2. **Per-card, inside `DashboardController::summary()`**: each block of
   cards is wrapped in its own `$user->hasPermission('{module}.{action}')`
   check before anything module-specific is queried or added to the
   response — `employees.view` for employee counts, `leave.view` for the
   leave summary (further refined by `leave.view_all`/`leave.view_team`,
   see below), `documents.view` for document counts, `policies.view`/
   `policies.view_acknowledgements`/`policies.acknowledge` for the three
   distinct policy cards. None of these are new checks — every one
   reuses the exact permission key its module's real page already
   requires.

### Leave card: reuses the real visibility rule, doesn't reimplement it

The `pending_leave` card's value and label both depend on which of three
tiers the caller holds — identical to `LeaveRequestController::index()`'s
own logic (Checkpoint 14):

| Permission held | Card label | Scope |
|---|---|---|
| `leave.view_all` | "Pending Leave Requests" | Every pending request in the tenant |
| `leave.view_team` (no `view_all`) | "Pending Leave Requests (My Team)" | Own + direct reports' (direct only), via `LeaveVisibilityService` |
| `leave.view` only | "My Pending Leave Requests" | Own only |
| `leave.view` held, no linked employee | "My Pending Leave Requests" | `0` — nothing to resolve, not an error |

`LeaveVisibilityService::visibleEmployeeIds()` is a verbatim extraction
of `LeaveRequestController`'s previously-private method — not a
reimplementation. Both callers now share one source of truth for "which
employee_ids can this user see leave for," so a future change to the
Checkpoint 14 manager-scope rule can't silently apply to one caller and
not the other. Confirmed behavior-identical: the full pre-existing Leave
test suite (123 tests across `LeaveRequestApiTest`, `ManagerScopedLeaveApprovalTest`,
`LeaveUiTest`, etc.) passes unchanged after the extraction — no test
needed updating, because the observable behavior didn't change, only
where the code lives.

The `recent_items` leave entries use the same scoping (own/team/all)
and show only `"Leave request — {status}"` linking to `/leave/{id}` —
never `reason`, `rejection_reason`, or any other free-text field.

### Document cards: self-scoped by necessity, not just by choice

Unlike leave, there is no `documents.view_all`-equivalent permission —
`documents.view` doesn't distinguish "see your own employee's documents"
from "see the whole tenant's." Showing a tenant-wide count to anyone
holding merely `documents.view` (which the Employee role also holds, for
their own self-service uploads) would hand a self-service user an
organization-wide figure — exactly the "dashboard becomes a data-leakage
shortcut" failure mode you explicitly warned against. So
`my_documents_expiring_soon`/`my_documents_recent` are **always** scoped
to `EmployeeDocument::query()->where('employee_id', $viewerEmployee->id)`,
for every role, including Tenant Admin/HR Manager — both cards are
simply absent if the viewer has no linked employee record, regardless of
how much `documents.view` they hold. Sensitive documents
(`is_sensitive`) are excluded from both counts unless the viewer also
holds `documents.view_sensitive`, mirroring `EmployeeDocumentController`'s
existing masking rule exactly (Checkpoint 8) — confirmed directly
(`test_document_cards_are_self_scoped_and_exclude_sensitive_unless_authorized`,
`test_document_cards_include_sensitive_documents_when_authorized`). No
document titles, filenames, or storage paths appear anywhere in the
response — only integer counts.

### Policy cards: three tiers, each independently gated

- `policies_total` (any `policies.view` holder) — a plain count, no
  policy content.
- `policies_pending_acknowledgement` (`policies.view_acknowledgements`
  only — HR/Admin/Auditor-level) — tenant-wide pending-acknowledgement
  count. Safe to be tenant-wide here, unlike documents, because this
  permission already exists specifically to distinguish "admin view of
  everyone's acknowledgements" from plain `policies.view` (Checkpoint 10).
- `my_policies_pending_acknowledgement` (`policies.acknowledge`, linked
  employee required) — the viewer's own pending count only.

No policy `content`/`summary`, no `approved_by`/`published_by`, no raw
acknowledgement `ip_address`/`user_agent` appear anywhere in the
response — the dashboard only ever returns integers and the small set
of safe labels/hrefs described above.

### Platform Super Admin: blocked at the API, safe at the page

`dashboard.view` is a tenant-scoped permission; a platform role can
never be assigned one — the same permission-assignment scope guard
(`HasPermissions`) that's protected every other tenant permission since
Checkpoint 4. This means `permission:dashboard.view` middleware alone
already returns `403` for any Platform Super Admin hitting
`GET /api/v1/dashboard`. `DashboardController::summary()` additionally
opens with `abort_if($user->is_platform_admin, 403, ...)` as defense in
depth — this isn't redundant paranoia: `BelongsToTenant`'s global scope
only filters a query when a `Tenant` is bound in the container, and nothing
is bound for a platform admin. Without this explicit check, a future
change to the route's middleware (or an as-yet-unbuilt platform
permission accidentally reusing the `dashboard.view` key) could make
every `count()` in this controller silently run **unscoped across every
tenant in the system** — confirmed this doesn't happen
(`test_platform_super_admin_is_blocked_from_dashboard_api`).

The **web** `/dashboard` page takes a deliberately different shape: no
blanket `permission:dashboard.view` middleware, because a platform
admin must still be able to open the page at all (to see a safe
"platform dashboard not available" message, per your Refinement 7) —
blocking them entirely would contradict that. `DashboardController`
(web)'s existing explicit-check style (already used for the pre-existing
isActive/tenant-active checks, since there's nothing for route-level
middleware to hang a check on for the one page every user reaches)
gained one more line: tenant users need `dashboard.view`, platform
admins are exempt, exactly like the checks already there. The frontend
never calls `/api/v1/dashboard` when `auth.user.is_platform_admin` is
true or `tenant` is `null` — it renders the safe message instead,
without a wasted (and would-be-`403`) request.

### A deliberate, intentional behavior change to three pre-existing tests

Before this checkpoint, `/dashboard` had no permission gate at all — any
active authenticated user could reach it. Three tests
(`InertiaAuthTest::test_authenticated_user_can_access_dashboard`,
`DashboardAndFrontendSecurityTest::test_shared_inertia_props_contain_no_sensitive_fields`,
`test_shared_props_expose_permission_list_to_frontend`,
`test_tenant_user_shared_props_reflect_only_their_own_tenant`) exercised
that with a bare permission-less user and asserted `200`. Now that
`dashboard.view` is required, these were updated to grant it explicitly
— the same "convert the test to match an intentionally new contract"
pattern already used in Checkpoint 16 when login/logout became
content-negotiated. A new test,
`test_authenticated_user_without_dashboard_view_cannot_access_dashboard`,
covers the now-restricted case these tests used to (accidentally) leave
untested.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. Verified via `tsc --noEmit`, `npm run build`, and a live
HTTPS smoke test: card grid responsiveness, the loading/error states
while `/api/v1/dashboard` is in flight, and the platform-admin message
rendering instead of a fetch attempt.

What *is* backend-tested (`DashboardApiTest`, 16 tests): guest/auth
requirements, `tenant.matches`, `dashboard.view` gating both directions,
per-card permission presence *and absence* (Refinement 9 — e.g. a user
without `employees.view` never receives `total_employees`/
`active_employees`, a Line Manager never receives a tenant-wide employee
count, an Employee never receives the tenant-wide acknowledgement
count), role-shaped responses for HR/Admin, Line Manager, and Employee,
tenant isolation, the Platform Super Admin block, and that no
sensitive/technical value (leave `reason`, policy `content`, `ip_address`,
`user_agent`, `storage_path`) ever appears anywhere in the response.

### Current limitations

- No tenant-wide document dashboard cards — see "Document cards" above;
  blocked on a `documents.view_all`-equivalent permission not existing.
- No charts, advanced analytics, export reports, or complex reporting.
- No notifications, calendar widgets, or scheduled-digest emails.
- No platform-level (cross-tenant) dashboard for Platform Super Admin —
  they see a plain static message, not real platform metrics.
- Recent items are capped at 3 per type (leave, employee) — no
  pagination, no "view all recent activity" page.
- No JS/TS unit test runner — see above.

### Future

- A tenant-wide document dashboard, once a `documents.view_all`-equivalent
  permission exists to gate it safely.
- Charts and richer analytics, once real reporting needs are identified.
- Export/reporting features building on the same summary data.
- A genuine platform-level dashboard for Platform Super Admin
  (cross-tenant health/usage metrics), architecturally separate from
  this tenant-scoped endpoint.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Settings Foundation

Replaces the Checkpoint 16 placeholder with real, permission-aware
section cards and one fully functional section (Company Profile) — see
[`architecture.md`](architecture.md#settings-foundation-checkpoint-22)
for the "singleton endpoint, pre-provisioned permissions" design and
[`api.md`](api.md#tenant) for the new endpoint reference.

### The rule, restated for the third module in a row

**`tenant.settings.view` grants reaching `/settings`. It grants nothing
else.** Every section card is independently gated by its own,
more-specific permission — `tenant.view` for Company Profile,
`users.view`/`roles.view` for Users & Access, `document_categories.view`,
`leave_types.view`, `audit.view` for Security & Audit. A user holding
only `tenant.settings.view` reaches a landing page with **zero** section
cards, not an error and not a fallback view of anything. This is
checked the same two ways as `dashboard.view` (Checkpoint 21):

1. **Explicit controller check** on the landing page
   (`SettingsController::index()`) — not blanket middleware, because a
   Platform Super Admin must still be able to open `/settings` (with a
   safe static message), and `tenant.settings.view` is a tenant-scoped
   permission they can never hold.
2. **Ordinary `permission:{key}` middleware** on every sub-page
   (`/settings/company`, `/settings/access`, etc.) — each one gated by
   the permission closest to what it will eventually manage, not by
   `tenant.settings.view` again (except `/settings/integrations`, which
   has no dedicated permission yet — see "Sections with no natural
   permission" below).

### The Tenant API: singleton, name-only, blocked for Platform Super Admin

`GET`/`PATCH /api/v1/tenant` take no route parameter — both actions
always operate on `app(Tenant::class)`, never a request-supplied ID
from the URL, body, or query string (Refinement 1). This isn't just a
convention; it's the actual mechanism that makes tenant-switching
through this endpoint structurally impossible, the same way `/me/employee`
(Checkpoint 11) makes it impossible to request another user's employee
record.

`UpdateTenantRequest` defines a validation rule for exactly one field:
`name` (Refinement 2). `subdomain`, `status`, `tenant_id`, `created_at`,
`updated_at`, `deleted_at`, and any future billing/security/system-flag
field are structurally absent — `FormRequest::validated()` only ever
returns keys that have a rule, so a request body containing any of
those is silently dropped before `TenantController::update()` ever sees
them, never partially applied. Confirmed directly
(`test_forbidden_fields_cannot_be_changed`): a `PATCH` sending `name`,
`subdomain`, `status`, and even a `tenant_id` pointing at a *different*
real tenant, all in the same request, changed only `name` — every other
field, including the cross-tenant `tenant_id` attempt, was silently
ignored. Confirmed live too: the same multi-field payload produced an
unchanged `subdomain`/`status` in the response.

**Platform Super Admin is blocked from `/api/v1/tenant` two ways**,
identical to the Dashboard's pattern: `permission:tenant.view`/
`tenant.update` middleware alone already returns `403` (a platform role
can never be assigned a tenant-scoped permission), and
`TenantController` additionally opens both `show()` and `update()` with
`abort_if($user->is_platform_admin, 403, ...)` as defense in depth —
`app(Tenant::class)` is never bound for a platform admin, so without
this explicit guard the endpoint would throw a raw, unhandled 500
instead of a clean `403` (confirmed:
`test_platform_super_admin_is_blocked_from_tenant_api`).

### Audit log on tenant update: safe metadata only (Refinement 3)

A `tenant.updated` audit entry is written only when `name` actually
changes (`$tenant->wasChanged('name')` — no-op saves don't create noise
log entries, same discipline as every other module). `metadata`
carries exactly `old_name`, `new_name`, `tenant_id`, `actor_user_id` —
all safe, all already visible to anyone who could make this change in
the first place. No secrets, no internal system configuration, nothing
beyond what the checkpoint asked for. Confirmed directly
(`test_tenant_update_writes_audit_log_with_safe_metadata`).

### Sections with no natural permission get the coarsest safe fallback, not an invented one

"Integrations" has no dedicated permission and no real data yet —
rather than inventing an `integrations.view` key for a page that
currently renders nothing, its route falls back to the same
`tenant.settings.view` umbrella check the landing page itself uses.
"Billing & Subscription" has no route at all — a static, unlinked card
on the landing page only, since a placeholder route with zero content
would be exactly the "broken link" the checkpoint's own instructions
warned against. Neither of these choices exposes any data; they're
purely about not manufacturing permission keys or routes ahead of a
real need.

### Role mapping: `tenant.settings.view` granted narrowly, `audit.view` closes a naming gap (Refinements 7/8)

| Role | `tenant.settings.view` | `tenant.view`/`tenant.update` | Sections visible |
|---|---|---|---|
| Tenant Admin | yes (wildcard) | yes (wildcard) | All |
| HR Manager | **yes** (new) | no | Document Categories, Leave Types |
| HR Officer | **yes** (new) | no | Leave Types |
| Auditor | **yes** (new) | no | Security & Audit |
| Employee | no | no | None — cannot reach `/settings` at all |
| Line Manager | no | no | None — cannot reach `/settings` at all |

Employee and Line Manager are deliberately **not** granted
`tenant.settings.view` — neither was mentioned in your suggested
per-role behavior, and "Employee should not access Settings unless
specifically granted" extends naturally to Line Manager by the same
conservative default. Confirmed live: both get a clean `403` from
`/settings`.

**`audit.view` was granted to Auditor** — research for this checkpoint
found the role held **no** audit permission at all despite its name (a
pre-existing gap from whenever Auditor was first seeded, unrelated to
this checkpoint's own changes). This is safe to close now because
nothing currently *does* anything with `audit.view` beyond gating the
"Security & Audit" placeholder card — audit logging itself remains
write-only (no viewing endpoint exists yet, see "Audit Logging" above),
so granting this permission exposes zero actual audit data this
checkpoint.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. Verified via `tsc --noEmit`, `npm run build`, and a live
HTTPS smoke test: section card visibility per role, the Company Profile
edit form's inline editing toggle, and the empty-state rendering when a
user holds `tenant.settings.view` but no section permissions at all.

What *is* backend-tested: `TenantApiTest` (11 tests) covers the
singleton endpoint's permission gating, forbidden-field rejection,
Platform Super Admin block, tenant isolation, and audit logging.
`SettingsUiTest` (9 tests) covers the landing page's permission gating,
Platform Super Admin safe behavior, cross-tenant session-reuse
blocking, `/settings/company`'s `tenant.view` requirement and IDs-only
props, every placeholder route's permission gating in both directions,
and that no secret/token/storage-path substring appears anywhere across
every Settings page's shared props.

### Current limitations

- No full user management UI — `/settings/access` is a permission-gated
  placeholder only.
- No full RBAC (roles/permissions) management UI — same placeholder,
  shared with Users & Access (see `docs/architecture.md` for why).
- No integrations, billing/subscription, or platform-wide tenant
  management — none of these have any backend to build a UI on yet.
- Only `name` is editable on the tenant profile — `subdomain`/`status`
  changes would need a dedicated, more carefully-designed admin flow
  (subdomain changes in particular touch DNS/routing assumptions
  throughout the app).
- No JS/TS unit test runner — see above.

### Future

- Full user management UI (deactivate and assign roles already exist
  since Checkpoint 23; create now exists since Checkpoint 43 — a real
  invite-email flow is the remaining piece).
- Full RBAC management UI (create/edit roles, assign permissions),
  building on the already-seeded `roles.*`/`permissions.*` permissions.
- Document category and leave type admin UIs, reusing existing APIs.
- A real audit log viewing UI, once a read endpoint exists.
- Integration settings, billing/subscription management, and a
  dedicated (carefully-scoped) subdomain/status change flow.
- A genuine platform-level tenant management surface for Platform Super
  Admin, architecturally separate from this tenant-scoped endpoint —
  the `platform.tenants.*` permissions already exist, unused.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Users & Access Management UI

The first checkpoint whose backend models — `User` and `Role` — cannot
rely on `BelongsToTenant`'s automatic tenant filtering. See
[`architecture.md`](architecture.md#users--access-management-ui-checkpoint-23)
for why that trait was never applied to these two models, and
[`api.md`](api.md#users--access) for the endpoint reference.

### The tenant boundary here is manual, and it is the *only* boundary

Every query in `UserController`, `RoleController`, and
`UserRoleController` explicitly filters:

```php
// Users
User::query()->where('tenant_id', app(Tenant::class)->id)->where('is_platform_admin', false)

// Roles
Role::query()->where('tenant_id', app(Tenant::class)->id)->where('is_platform_role', false)
```

Unlike every prior module (where a missing explicit check would still
be caught by `BelongsToTenant`'s global scope), a mistake here has no
backstop from the model layer — this is why the test suite for this
checkpoint puts unusually heavy weight on tenant-isolation and
platform-scope tests specifically (`RoleApiTest`/`UserApiTest`'s
tenant-A-cannot-see-tenant-B and platform-record-unreachable tests
exist to prove the *primary* defense works, not a secondary one).

`show()`/`update()`/role-assignment additionally repeat the check via
an explicit `abort_if($target->is_platform_admin, 404)` (users) /
`abort_if($target->is_platform_role, 404)` (roles) — even though the
tenant_id filter alone already excludes platform records (their
`tenant_id` is always `null`, which can never equal a real tenant's
id), stating the platform-exclusion rule explicitly means a future
refactor that changes how the tenant filter is expressed can't
silently reopen this by accident.

### `UserResource`/`RoleResource`: narrow by construction

`UserResource` returns `id`, `name`, `email`, `status`,
`is_platform_admin` (always `false` here, since this Resource never
wraps a platform admin record), a safe `roles` summary
(`id`/`name`/`slug` only — never the `user_role` pivot row), a safe
`linked_employee` summary (`id`/`full_name` only — never the full
employee record), `last_login_at`, and `created_at`. It never returns
`password`/`remember_token` (already globally hidden on the model via
`#[Hidden]`, and doubly so by this Resource simply never referencing
them), `last_login_ip`, or `email_verified_at`. `RoleResource` returns
`id`/`name`/`slug`/`description`/`is_platform_role`/a computed
`permission_count` — never the raw `role_permission` pivot rows or a
role's actual permission list. Confirmed directly
(`test_user_api_does_not_expose_sensitive_fields`,
`test_role_api_does_not_expose_raw_pivot_internals`).

### Status update: one field, two safeguards

`PATCH /api/v1/users/{user}` — `UpdateUserStatusRequest` validates
exactly `status` (`active`/`inactive`/`suspended`). `name`, `email`,
`password`, `tenant_id`, `is_platform_admin`, `email_verified_at`,
`last_login_at`, `last_login_ip`, `remember_token`, roles, permissions,
and employee-link fields are structurally absent from the rules — a
request body containing any of them has those keys silently dropped
before the controller ever sees them, never partially applied.
Confirmed directly (`test_status_update_ignores_forbidden_fields` —
sends `name`/`email`/`is_platform_admin`/`tenant_id`/`password`
alongside a valid `status` change, only `status` takes effect).

Gated by `users.deactivate` (not `users.update`, which stays seeded but
unused this checkpoint — reserved for a future general profile-edit
feature that isn't built).

### "Never leave a tenant without an active Tenant Admin" — one rule, two dangerous paths, one method

`TenantAdminProtectionService::wouldLeaveTenantWithoutAdmin(User $user)`
answers exactly one question: does at least one *other* user in this
tenant hold the `tenant-admin`-slugged role? Both dangerous actions
check it before proceeding:

- **Status update** (`UserController::update()`): if the target is
  currently `active` and the requested status is not, and this method
  returns `true`, the change is rejected with `409` — regardless of
  who's making the change. This is deliberately broader than "cannot
  deactivate *themselves*" (the literal instruction) — a second admin
  deactivating the *other* sole remaining admin is exactly as
  dangerous as doing it to yourself, so the check doesn't special-case
  the actor.
- **Role removal** (`UserRoleController::destroy()`): if the role
  being removed has slug `tenant-admin` and this method returns `true`,
  the removal is rejected with `409`.

Both confirmed directly and from both angles — blocked when it's the
last admin (`test_cannot_deactivate_last_active_tenant_admin`,
`test_cannot_remove_last_tenant_admin_role`), allowed when another
admin exists (`test_can_deactivate_tenant_admin_when_another_admin_exists`,
`test_can_remove_tenant_admin_role_when_another_admin_exists`).

### Role assignment: layered, not single-point

`POST /api/v1/users/{user}/roles` — `AssignUserRoleRequest` validates
`role_id` against `Rule::exists('roles', 'id')` scoped to
`tenant_id = current tenant AND is_platform_role = false` (a `422` for
a platform role or another tenant's role, before anything touches the
model). `User::assignRole()` independently re-checks the identical
scope rule and throws if violated — normally unreachable given the
FormRequest layer, kept as a backstop regardless (the same "two layers,
not one relying on the other" principle every module in this app
follows). Both `assignRole()`/`removeRole()` already wrote
`role.assigned`/`role.removed` audit logs since Checkpoint 4/5 — this
checkpoint didn't add new audit logging here, just a UI and the
Tenant-Admin-protection check on top.

### Role mapping: management stays Tenant-Admin-only this checkpoint

| Role | `users.view` | `users.deactivate`/`users.assign_role`/`roles.view`/`permissions.view` |
|---|---|---|
| Tenant Admin | yes (wildcard) | yes (wildcard) |
| HR Manager | yes (existing, unchanged) | no |
| Everyone else | no | no |

No new grants were made this checkpoint. HR Manager already held
`users.view` (a read-only capability from an earlier checkpoint) and
keeps exactly that — status changes, role assignment/removal, and the
role list stay Tenant-Admin-only, per your explicit "keep role/status
management Tenant-Admin-only for now" instruction. This is
deliberately the most conservative reading available: broadening any
of these later is a low-risk, additive change; narrowing them after
the fact (if a broader grant turned out to be a mistake) is not.

### Employee linking: no new backend surface, just a picker

The employee-link/unlink UI on the user detail page calls the existing
`POST`/`DELETE /employees/{employee}/link-user`/`unlink-user`
(Checkpoint 11) unchanged — cross-tenant rejection, terminated-employee
rejection, already-linked-employee rejection, and already-linked-user
rejection are all already enforced there and were not touched. The
employee picker is a real `<select>` populated from
`GET /api/v1/employees` (never free-text), filtered client-side to
exclude `status: terminated` — it cannot also exclude *already-linked*
employees, since `EmployeeResource` has no `user_id` field to check;
picking one surfaces the backend's existing, clear validation error
instead. Backend remains the authority regardless of what the picker
does or doesn't pre-filter.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. Verified via `tsc --noEmit`, `npm run build`, and a live
HTTPS smoke test: the status `<select>` and its confirmation prompt,
the role-assign/remove buttons' visibility based on `useCan()`, the
employee picker, and every success/error banner.

What *is* backend-tested (`UserApiTest` 16, `RoleApiTest` 8,
`UserRoleApiTest` 11, `UsersAccessUiTest` 9 — 44 new tests total):
permission gating both directions on every endpoint and page, tenant
isolation for both users and roles (list and single-record), platform
admin/platform role unreachability, forbidden-field rejection on
status update, the last-Tenant-Admin protection from both angles and
both dangerous paths, role-assignment scope violations (platform role,
cross-tenant role, cross-tenant user), audit logging for both role
assignment and removal, and that no sensitive field or raw pivot
internal appears anywhere in either resource's response.

### Current limitations

- No user invitation flow — an admin-driven create action exists
  (`POST /api/v1/users`, Checkpoint 43 — see
  [User Account Provisioning](#user-account-provisioning-checkpoint-43)
  below), and a real self-service password reset flow exists (Checkpoint
  44 — see
  [Password Reset](#password-reset-checkpoint-44) below), but there's
  still no invite-email specifically: the admin creating an account
  still sets its real initial password directly, rather than the new
  user setting their own via a reset-style link on first login.
- No MFA setup or SSO configuration.
- No direct permission grant UI — `GET /api/v1/permissions` is
  read-only and unused by any write path this checkpoint; direct
  grants (`UserPermission`, Checkpoint 4) remain API/tinker-only.
- No temporary/time-boxed permission UI.
- No access review workflows or segregation-of-duties engine.
- No platform tenant management UI, bulk user import, or SCIM/directory
  sync.
- Role/status management stays Tenant-Admin-only — HR Manager and
  every other role remain read-only (or entirely unable to reach
  Users & Access) this checkpoint.

### Future

- An invite-email flow specifically (new-account creation still sets a
  real password directly rather than emailing a set-your-own-password
  link) — the remaining gap after Checkpoint 43's create action and
  Checkpoint 44's password reset flow; see
  [Password Reset](#password-reset-checkpoint-44) below.
- MFA setup UI.
- A direct permission grant UI, reusing `User::grantPermission()`/
  `revokePermission()` (already built, Checkpoint 4).
- Temporary/time-boxed permissions (an `expires_at` column on
  `user_permissions`, already flagged as future work since Checkpoint 4).
- Access review workflows and segregation-of-duties tooling.
- SSO/SCIM directory sync, once an identity provider integration is
  scoped.
- Broadening role/status management beyond Tenant Admin, if a real
  need for it is identified (e.g. HR Manager granted `users.assign_role`
  for a narrow, well-justified reason).
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Audit Log Viewing UI

The first checkpoint to read from `audit_logs`, not just write to it —
see
[`architecture.md`](architecture.md#audit-log-viewing-ui-checkpoint-24)
for the "structurally read-only, and structurally already was" design,
and [`api.md`](api.md#audit-logs) for the endpoint reference.

### The tenant boundary here is manual, same weight as Checkpoint 23

`AuditLog` does not use `BelongsToTenant` (a Checkpoint 5 design
decision — audit events happen in contexts where an ambient bound
tenant would be unreliable). `AuditLogController::index()`/`show()`
manually filter `where('tenant_id', app(Tenant::class)->id)` as the
*primary* tenant boundary, not a backstop — the same weight of concern,
and the same weight of testing, as `User`/`Role` in Checkpoint 23.
`show()` additionally repeats the check via explicit
`abort_unless($auditLog->tenant_id === app(Tenant::class)->id, 404)` —
a platform-level log (`tenant_id: null`) can never match a real
tenant's id, so this also naturally rejects those without a separate
check. Confirmed directly
(`test_tenant_a_cannot_list_tenant_b_audit_logs`,
`test_tenant_a_cannot_view_tenant_b_audit_log_by_guessed_id`,
`test_platform_level_audit_log_is_not_reachable_through_tenant_api`).

### Platform Super Admin: blocked at the API, same as Checkpoint 23's pattern

`audit.view` is a tenant-scoped permission a platform role can never be
assigned — `permission:audit.view` middleware alone already returns
`403` for a Platform Super Admin hitting either endpoint.
`AuditLogController` additionally opens both methods with
`abort_if($user->is_platform_admin, 403, ...)` as defense in depth,
for the identical reason as `DashboardController`/`TenantController`/
`UserController`: `app(Tenant::class)` is never bound for a platform
admin, so an unfiltered query here would otherwise span every tenant
in the system. Confirmed directly
(`test_platform_super_admin_is_blocked_from_tenant_audit_api`) and
live. Unlike the top-level `/settings` landing page (which has a
special platform-admin-safe-message exemption), `/settings/security`
and every audit sub-page use ordinary `permission:audit.view`
middleware and correctly `403` a platform admin — consistent with
every other Settings *sub-page* (Company, Access, Document Categories,
Leave Types), where only the landing page itself gets the exemption.

### `AuditValueSanitizer`: new protection for `metadata`, defense-in-depth for the rest

`AuditLogger`'s existing write-time masking (Checkpoint 5/12) only ever
covered `old_values`/`new_values` — `metadata` was deliberately left
unmasked, under the assumption that callers would only ever put small,
safe contextual tags there. `AuditValueSanitizer::sanitize()` is a new,
broader pass applied at the `Resource` layer to all three fields
uniformly, regardless of whatever masking already happened at write
time:

```php
private const SENSITIVE_KEY_PATTERNS = [
    'password', 'token', 'secret', 'key', 'authorization', 'cookie',
    'session', 'remember', 'reset', 'bank', 'iban', 'salary', 'medical',
    'reason', 'rejection_reason', 'storage_path', 'stored_filename',
    'file_path', 'private_path', 'ip_address', 'user_agent',
];
```

Matched case-insensitively, by substring — the same technique
`AuditLogger` already used, just with a fuller list. This is
deliberately broader than strictly necessary: a value like
`permission_key` (from `role.assigned`/`permission.granted` audit
entries, Checkpoint 4) gets masked purely because it contains `key` —
an accepted false positive, not a bug. Over-masking a few harmless
fields is the correct tradeoff for a sanitizer whose job is catching
values nobody explicitly reviewed for this exact purpose. Confirmed
directly with real seeded values for every category — `password`,
`api_key`, `bank_account_number`, `reason` (a realistic free-text
medical-sounding string), `storage_path`, and `ip_address`-as-a-metadata-key
all masked; a plain `employee_id` value passed through unmasked
(`test_audit_log_resource_masks_sensitive_metadata_keys`,
`test_audit_log_resource_masks_sensitive_old_values_keys`,
`test_audit_log_resource_masks_sensitive_new_values_keys`).

### `ip_address`/`user_agent`: omitted entirely, not just optional

Per your explicit "if unsure, omit entirely" instruction,
`AuditLogResource` never returns `ip_address`/`user_agent` at all —
not on the list, not on the detail view, regardless of severity or
context. Confirmed directly
(`test_audit_log_resource_never_exposes_ip_address_or_user_agent` —
seeds a log with a real IP and a distinctive user-agent string,
asserts both the key names and the actual values are absent from the
full serialized response).

### Filters: validated against known values, applied after the tenant filter

`module`/`action`/`actor_user_id`/`target_user_id` are plain scalar
filters; `severity` is validated against the three values actually
used anywhere in this codebase (`info`/`warning`/`critical` —
confirmed by grepping every `AuditLogger::log()`/`logFor()` call site
during research for this checkpoint, not guessed); `date_from`/`date_to`
are validated as real dates, with `date_to` required to be
`after_or_equal:date_from`. Every filter is applied via Eloquent's
`when()` *after* the mandatory `where('tenant_id', ...)` clause already
exists — a filter can narrow the result set, but nothing about the
filter mechanism itself provides a path around the tenant boundary
(there's no `tenant_id` filter key exposed to accept from the request
in the first place). Confirmed directly
(`test_module_filter_is_tenant_scoped` — two matching-module logs
exist, one in the caller's tenant and one in a different tenant; only
the caller's own is returned).

### Actor/target names: resolved client-side, reusing an existing endpoint

`AuditLogResource` returns only `actor_user_id`/`target_user_id` as
plain integers — no name, no new backend join. The Audit Logs list and
detail pages each fetch `GET /api/v1/users` (Checkpoint 23, already
tenant-scoped and tested) once and build a client-side ID→name lookup
(`formatActorRef()` in `resources/js/lib/format.ts`), falling back to
`System` for system-actor entries (`actor_user_id: null`) or a plain
`User #N` reference if a name can't be resolved. No cross-tenant lookup
risk — the endpoint being reused was already tenant-scoped for its own
reasons, and this checkpoint adds no new query.

### Rendering: sanitized values as clean rows, never a debug dump

The detail page's `KeyValueList` component renders each sanitized
`metadata`/`old_values`/`new_values` entry as a plain, escaped
key-value row via ordinary JSX text interpolation — never
`dangerouslySetInnerHTML`, never a raw `JSON.stringify()` styled like a
debug panel. Values are already safe by the time this component
receives them (server-side sanitisation already happened); this
component's only job is displaying text cleanly, not a second line of
defense.

### Structurally read-only, confirmed by inspecting the route table itself

No `store()`/`update()`/`destroy()` exists on `AuditLogController`, and
no `POST`/`PUT`/`PATCH`/`DELETE` route is registered for any
`audit-logs` URI — confirmed not by absence of code, but by a test that
inspects Laravel's actual registered route list and asserts no write
method exists on any matching route
(`test_no_audit_log_write_routes_exist`). This is in addition to
`AuditLog::save()`/`delete()` already throwing at the model layer
(Checkpoint 5) if either were ever somehow reached — two independent
reasons this can never become writable by accident.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. Verified via `tsc --noEmit`, `npm run build`, and a live
HTTPS smoke test: the filter form, the actor/target name lookup
rendering, and the sanitized key-value list layout on the detail page.

What *is* backend-tested (`AuditLogApiTest` 17, `AuditLogUiTest` 7 — 24
new tests): permission gating both directions on API and UI, tenant
isolation for list and single-record (the primary defense), platform-level-log
and Platform-Super-Admin unreachability, sanitisation of all three
JSON fields with real sensitive values, `ip_address`/`user_agent`
omission, filter validation and tenant-scoping, pagination, cross-tenant
`404`, IDs-only UI props, and the structural no-write-route test.

### Current limitations

- No audit export, reporting, or compliance report generation.
- No SIEM integration or alerting.
- No anomaly detection.
- No advanced search — only the basic filters listed above.
- No saved filters.
- No platform-wide (cross-tenant) audit dashboard for Platform Super
  Admin.
- No retention/archival controls — logs accumulate indefinitely (no
  deletion path exists at all, by design, but also no automated
  pruning).
- Actor/target names depend on `/api/v1/users`, which excludes
  soft-deleted users by default — a since-deleted user's historical
  actions show as `User #N` rather than their name.
- No JS/TS unit test runner — see above.

### Future

- Audit export and compliance reporting, building on the same
  sanitized data.
- SIEM integration, once a real integration target is identified.
- Alerting/anomaly detection for unusual audit patterns.
- Advanced search and saved filters.
- A genuine platform-level audit dashboard for Platform Super Admin,
  architecturally separate from this tenant-scoped endpoint.
- Retention/archival policy controls, once a real compliance
  requirement is scoped.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Document Categories & Leave Types Admin UI

The first module UI checkpoint that needed no new backend endpoint at
all — see
[`architecture.md`](architecture.md#document-categories--leave-types-admin-ui-checkpoint-25)
for why, and [`api.md`](api.md#document-categories) /
[`api.md`](api.md#leave-types) for the (unchanged) endpoint reference.

### Tenant isolation: the standard pattern, not the Checkpoint 23/24 exception

Both `DocumentCategory` and `LeaveType` already use `BelongsToTenant` —
the same two-layer defense every module before Checkpoint 23 relies on
(global scope, plus the controller's own explicit
`ensureBelongsToCurrentTenant()` check as defense in depth). Confirmed
directly (`test_cross_tenant_document_category_id_returns_404_on_edit_page`,
`test_cross_tenant_leave_type_id_returns_404_on_edit_page`) and live —
a cross-tenant ID returns `404`, the same pre-existing status-code
nuance documented under Checkpoint 13 (route-model-binding's tenant
scope resolves before `tenant.matches` would otherwise return `403`
for a genuinely reused cross-tenant session).

### Resource tightening: `created_by`/`updated_by` removed, not just hidden

`DocumentCategoryResource`/`LeaveTypeResource` no longer return
`created_by`/`updated_by` (Refinement 1) — both fields existed since
their original checkpoints (9 and 12) with no consumer using them.
Removed rather than left in "just in case," per your explicit
instruction and this app's general preference against carrying unused
surface area. Verified safe to remove by checking first: no test in
either module's existing suite asserted these fields' presence in a
JSON response (the sole `created_by` reference,
`LeaveTypeApiTest::test_user_with_permission_can_create_leave_type`,
checks the database row via `assertDatabaseHas()`, not the API
response) — confirmed by re-running both suites unchanged immediately
after the removal, before writing anything new on top.
`tenant_id`/`deleted_at` were never returned by either Resource in the
first place.

### Delete is "Archive" everywhere in the UI, because that's what the backend actually does

Both `destroy()` methods (Checkpoints 9/12) are soft-delete-only — there
is no hard-delete code path in either API. The list page's action is
labelled "Archive," not "Delete," and its confirmation dialog states
plainly what will happen ("no longer selectable for new document
uploads / leave requests"). No optimistic removal — the row disappears
only after a full refetch confirms the backend actually archived it
(Refinement 5), same pattern as every other module's delete/archive
action since Checkpoint 17.

### Sensitive and expiry-required categories: badges reflect data already returned, not a new rule

The Document Categories list shows a "Sensitive" badge for
`is_sensitive: true` and an "Expiry required" badge for
`requires_expiry_date: true` — purely reflecting fields the API already
returned since Checkpoint 9. No new enforcement exists or is needed
here: `EmployeeDocumentController`'s existing sensitive-document
exclusion (Checkpoint 8) and `StoreEmployeeDocumentRequest`'s existing
expiry-date requirement (Checkpoint 8/9) are completely unchanged —
this UI only ever *labels* categories consistently with rules the
backend already enforces elsewhere.

### `max_days_per_year`: the one form field that breaks this app's usual "omit if blank" rule

Every other optional field in every Create/Edit form in this app
follows the same convention: blank means "don't send this key at all,"
which the backend then correctly interprets as "don't change this
value." `max_days_per_year` on the **Leave Type Edit** form is the
deliberate exception (Refinement 4): a blank value is sent as an
*explicit* `null`, because `UpdateLeaveTypeRequest`'s validation rule
for this field has no `sometimes` — an *absent* key leaves the existing
value untouched forever, while an explicit `null` genuinely clears it
to "unlimited." Without this exception, any leave type ever given a
numeric cap could never be turned back into unlimited through this UI.
Confirmed directly
(`test_max_days_per_year_can_be_cleared_to_null` — creates a leave type
with `max_days_per_year: 21`, sends an explicit `null` via `PATCH`,
asserts the database row is genuinely `null` afterward) and live. The
**Create** form doesn't need this exception — a brand-new leave type
has no old value to accidentally preserve, so a blank field there is
simply omitted, and the database column's own default (`null`) applies
either way.

### Editing configuration never rewrites existing balances — a schema property, not new enforcement

`leave_types` and `leave_balances` are separate tables with no
cascading update between them; `LeaveTypeController::update()` only
ever calls `$leaveType->save()`. Changing a leave type's
`max_days_per_year` (or any other field) after employees already have
`LeaveBalance` rows referencing it does not retroactively touch those
rows — this was already true before this checkpoint and required no
new code to preserve. The Edit form's helper text stating this is
purely informational.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. Verified via `tsc --noEmit`, `npm run build`, and a live
HTTPS smoke test: the create/edit forms' checkboxes and status
dropdown, the archive confirmation dialog, and the sensitive/expiry
badges' rendering.

What *is* backend-tested (`DocumentCategoryUiTest` 12,
`LeaveTypeUiTest` 13 — 25 new tests): permission gating both directions
on list/create/edit routes for both modules, tenant isolation (the
standard two-layer pattern), IDs-only props on the edit pages, that
neither Resource exposes `created_by`/`updated_by`/`deleted_at`/
`tenant_id`, and the `max_days_per_year`-to-`null` behavior specifically.
Delete/archive permission gating and the full create/update/archive
API behavior remain covered by each module's own pre-existing API test
suite (`DocumentCategoryApiTest`, `LeaveTypeApiTest`, Checkpoints 9/12)
— this checkpoint didn't duplicate that coverage, only re-asserted the
permission-gating boundary once per module at the API level.

### Current limitations

- No bulk import/export of categories or leave types.
- No advanced configuration audit reports (beyond the existing generic
  Audit Log viewer, Checkpoint 24, which already records every create/
  update/archive here under the `documents`/`leave` modules).
- No department/location/job-title admin, payroll configuration,
  accrual engine beyond existing leave balance behavior, document
  approval workflow, or policy category admin.
- No notification templates or workflow rules tied to configuration
  changes.
- No JS/TS unit test runner — see above.

### Future

- Bulk import/export for categories and leave types, if a real need
  emerges.
- Department/location/job-title admin screens, following this same
  pattern once those models exist.
- Configuration-change-triggered notifications, once a notification
  system exists at all.
- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable.

## Demo Readiness & UI Polish (Checkpoint 26)

No new business module, no new permission, and no new endpoint this
checkpoint — the whole job was making the ten already-built modules
demo-ready, plus fixing two concrete rough edges a systematic review
found. See
[`architecture.md`](architecture.md#demo-readiness--ui-polish-checkpoint-26)
for the full technical writeup; this section covers the security-review
angle specifically.

### The Sidebar fix changed a UI hint, not a security boundary

`Sidebar.tsx`'s "Settings" nav link checked `employees.update` — a
permission that predates `tenant.settings.view` (Checkpoint 22), the
permission that actually gates the `/settings` route server-side. HR
Officer and Auditor both hold `tenant.settings.view` but not
`employees.update`, so both could already reach `/settings` directly by
URL; the sidebar just never told them the link existed. Fixed by
pointing the nav check at `tenant.settings.view`, matching the real
gate. `SettingsController`'s server-side check
(`tenant.settings.view` middleware) was never touched — this was
purely correcting which permission the *hint* reads, the same
"frontend decides what to render, backend decides what's allowed" rule
this app has followed since its first frontend checkpoint. Confirmed
directly (new `SettingsNavPermissionTest` — asserts the shared
`auth.user.permissions` prop includes/excludes `tenant.settings.view`
correctly per role, and separately re-asserts `/settings` itself still
403s for a user holding only the old `employees.update`) and live
(HR Officer and Auditor both reached `/settings` in the smoke test;
Employee, who holds neither permission, was still blocked).

### The Settings hub's stale "Coming later" labels were a documentation bug, not a security issue

Five of `Settings/Index.tsx`'s section cards (Users & Access, Roles &
Permissions, Document Categories, Leave Types, Security & Audit) were
still flagged `comingLater: true` from before their real pages existed
(Checkpoints 23–25 built all five). Each card's actual gate is its own
`useCan(section.permission)` check, unrelated to the `comingLater`
label — so this never granted or hid access to anything; it only made
finished work look unfinished. Fixed by flipping the flag on the five
sections that are real. Integrations and the static Billing &
Subscription card correctly remain marked "Coming later" — neither has
a page.

### `DemoDataSeeder`: realistic data, the same trust boundary as every other seeder

Everything `DemoDataSeeder` creates is plain Eloquent
`firstOrCreate`/`updateOrCreate` against models that already enforce
their own tenant scoping (`BelongsToTenant` on Employee/LeaveType/
DocumentCategory/Policy/etc.) — the seeder runs with the same trust
level `DatabaseSeeder` always has, not a new privileged path. No public
storage disk is used for the four seeded documents (`local` disk only,
matching `EmployeeDocumentFactory`'s existing safe pattern); no
hardcoded bypass user, disabled middleware, exposed hidden ID, or
platform-admin-with-tenant-permissions was introduced. `DemoDataSeeder`
only seeds the `uesl` tenant — the tenant count doesn't grow, and
`airpeace`/`ibom` remain exactly as `TenantSeeder`/`UserSeeder` already
set them up. Idempotency (`test_demo_data_seeder_is_idempotent_on_a_second_run`)
and orphaned-FK absence
(`test_demo_data_seeder_has_no_orphaned_foreign_keys`) are both
directly tested, alongside a coverage test confirming every required
demo scenario (pending/approved/rejected leave, sensitive/expiry
documents, draft/published/assigned policies) actually exists after
seeding.

### Three new demo logins, same role/permission sets — no new grants

`hr.officer@uesl.peopleos.test`, `line.manager@uesl.peopleos.test`, and
`auditor@uesl.peopleos.test` are new *users*, assigned their role's
pre-existing, unchanged permission set (Checkpoint 4/5's
`assignRole()`, which already writes its own `role.assigned` audit log
entry — this is where this checkpoint's naturally-arising audit trail
comes from, not a fabricated entry). No permission was added, widened,
or removed on any role to accommodate these accounts.

### Build-size fix: a lazy-loading config change, not a security-relevant change

See `architecture.md` for the technical detail (`app.tsx`'s resolver
switched from an eager to a lazy `import.meta.glob`). Worth noting here
only because it's a genuine runtime behavior change (component
resolution is now asynchronous) rather than a pure build-config number
— verified via `tsc --noEmit`, `npm run build`, and the full live
smoke test across all seven roles to confirm every page still renders
correctly under the new resolution path. No route, middleware,
permission check, or Resource was touched by this change.

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. The Settings nav link's actual visibility (i.e., that
`Sidebar.tsx` correctly hides/shows the link based on the permission
array) cannot be exercised by a PHP test; what can be, and is, tested
is the underlying fact the nav logic depends on — the shared
`auth.user.permissions` prop's contents per role (`SettingsNavPermissionTest`).
Actual link visibility was confirmed by inspecting the built
`Sidebar.tsx` change directly and by the live smoke test's per-role
permission-prop check.

### Current limitations

- Demo users have no invitation flow, self-registration, password
  reset UI, MFA, or SSO — they are pre-seeded accounts only.
- No RBAC role/permission *editing* UI — the Users & Access module
  remains view/assign-only (Checkpoint 23), unchanged this checkpoint.
- Seeded documents use safe fake file contents on the private `local`
  disk — there is no real file content to preview, only metadata.
- Leave balances are a fixed, consistent seeded snapshot, not the
  output of a running accrual engine (none exists yet — see Leave
  Balances Foundation above).
- No frontend test runner still — see "What is not, and cannot be,
  tested by a JS runner" above.

### Future

- Frontend test tooling (Vitest + React Testing Library), if
  component-level testing becomes valuable — this checkpoint's
  Settings-nav gap (a stale permission check with no test catching it
  for several checkpoints) is a concrete example of the kind of bug
  such tooling would catch earlier.
- A lint rule or CI check that a `Sidebar.tsx` nav link's permission
  matches its destination route's actual gate, so this specific class
  of drift can't recur silently.
- Revisit demo data realism as new modules are added (payroll,
  onboarding, performance) rather than letting `DemoDataSeeder` grow
  unbounded — split into per-module seeders if it does.

## Deployment & Production Hardening (Checkpoint 27)

No new business module, no new permission, no new endpoint, and no
middleware change this checkpoint — the whole job was reviewing what
already exists against what a real production deployment requires, and
writing down what was found. See `docs/deployment.md` for the full
technical detail and `docs/production-readiness.md` for the checklist
this section supports.

### `TrustProxies` is not configured — a real gap, documented not silently patched

`bootstrap/app.php` registers `ResolveTenant`, `HandleInertiaRequests`,
and the `permission`/`tenant.matches` aliases, but no
`Application::configure()->trustProxies(...)` call exists. If this app
is ever deployed behind a reverse proxy or load balancer that
terminates TLS (the request arrives at the PHP process as plain HTTP,
with the original scheme carried in an `X-Forwarded-Proto` header),
Laravel won't trust that header by default — `$request->isSecure()`
can return `false` even though the original client connection was
HTTPS, which affects secure-cookie behavior and any scheme-sensitive
logic. This wasn't silently patched with a guessed proxy configuration
(the real production topology — direct TLS termination on the app
server vs. behind a load balancer, and if the latter, which IPs/ranges
to trust — isn't something this checkpoint can know) — it's documented
as a required production step in `docs/production-readiness.md`
instead. No security regression exists in the current local/demo setup
(Laragon's Apache terminates TLS directly, no intermediate proxy).

### `SESSION_SECURE_COOKIE` needs an explicit production value

`.env.example` didn't previously list `SESSION_SECURE_COOKIE`,
`SESSION_HTTP_ONLY`, or `SESSION_SAME_SITE` at all, even though
`config/session.php` already reads all three
(`env('SESSION_SECURE_COOKIE')`, defaulting to `null`; the other two
already had safe framework defaults — `http_only` defaults `true`,
`same_site` defaults `'lax'`). Local/demo behavior is unaffected by
adding these (the values now shown match what was already in effect by
default), but a production `.env` must explicitly set
`SESSION_SECURE_COOKIE=true` — see `docs/deployment.md` §3 for why an
unset value is only "usually correct" (dependent on correct HTTPS
scheme detection, which is exactly what the `TrustProxies` gap above
can undermine behind a proxy).

### The tenant-route audit becomes a real, committed, tested artifact instead of a re-derived script

Every checkpoint since roughly Checkpoint 13 has re-run a hand-written
scratch-directory PHP script confirming every `auth`-protected route
also carries `tenant.matches` — useful, but it never lived in the
repository, so it couldn't be run by anyone (or any future session)
without reconstructing it from memory first. `php artisan
route:audit-tenant-scoping` (new — `App\Console\Commands\AuditTenantRouteScoping`)
formalizes exactly the same check directly against `Route::getRoutes()`
(no intermediate JSON file, no scratch directory), and
`AuditTenantRouteScopingCommandTest` runs it as a real regression test.
This is read-only — it inspects already-registered routes and exits
non-zero if anything is missing; it changes no middleware, no route,
and no permission.

### Storage, logging, and demo-seeder review confirmed existing practice, changed nothing

- **Private storage**: confirmed (again) that no code path anywhere in
  `app/` references the `public` disk — every document stays on the
  private `local` disk, as established since Checkpoint 8.
- **Logging vs. audit logging**: confirmed these remain two genuinely
  separate systems (application logs for operators, `audit_logs` for
  compliance/security review) — see `docs/deployment.md` §5 for the
  reasoning, unchanged from prior checkpoints, just written down
  explicitly for the first time here.
- **Demo seeders**: `UserSeeder`'s demo logins and `DemoDataSeeder`
  (Checkpoint 26) hold no elevated or bypass privilege — each demo
  user has exactly its role's normal permission set. The production
  rule (never run the full `DatabaseSeeder` chain against production;
  seed `Tenant`/`Permission`/`Role` individually if needed at all) is
  now explicit in `docs/production-readiness.md` rather than assumed.

### What is not, and cannot be, tested by a JS runner

Unchanged — no Jest/Vitest configured. Nothing in this checkpoint
touched frontend behavior at all (documentation and one backend-only
Artisan command), so this checkpoint needed no new frontend test
regardless.

### Current limitations

- `TrustProxies` remains unconfigured — a real production deployment
  behind a reverse proxy/load balancer must add this before going live
  (see above). Not fixed this checkpoint because the correct
  configuration depends on the actual production topology, which isn't
  known yet.
- No automated backup/restore tooling — `docs/deployment.md` documents
  the practice (what to back up, in what combination), not an
  automated backup mechanism. Purely operational guidance.
- No CI pipeline still runs any of this automatically (route audit,
  test suite, Pint, `tsc`) — all verification remains manual, run
  locally before each checkpoint/deployment.

### Future

- Add real `trustProxies()` configuration once the actual production
  hosting topology (direct TLS termination vs. behind a load balancer)
  is known.
- A CI pipeline that runs the full verification set (test suite, Pint,
  `tsc`, `route:audit-tenant-scoping`, `npm run build`) on every push,
  rather than relying on manual pre-checkpoint runs.
- Automated backup tooling/runbooks, once a real hosting environment
  exists to automate against.

## RBAC Role & Permission Management UI (Checkpoint 28)

`/settings/access/roles` gains create/edit/permission-assignment on top
of Checkpoint 23's read-only list — but only for **custom** roles. See
[`architecture.md`](architecture.md#rbac-role--permission-management-ui-checkpoint-28)
for the technical writeup; this section covers the security design.

### The built-in/custom distinction didn't exist — added deliberately, with your approval

The `roles` table had no column distinguishing a seeded role (Tenant
Admin, HR Manager, etc.) from an admin-created one. Rather than infer
this from something indirect (e.g. "was this role created before the
oldest employee record" — fragile and wrong), a new `is_system_role`
boolean column was added, backfilled `true` for every pre-existing row,
and `RoleSeeder` now sets it explicitly (`true`) on every role it
creates. Any role created through the new `POST /api/v1/roles`
endpoint is always `is_system_role: false` — set by the controller from
trusted context, never accepted from request input.

### Safer MVP: system roles are fully view-only, not "carefully editable"

Rather than build logic to decide "would removing this permission from
Tenant Admin leave the tenant without an effective admin path" (complex,
and wrong in a way that's hard to fully test), this checkpoint takes
the simpler, safer route you approved: **a system role can never be
mutated through this feature at all** — no name/description edit, no
permission add/remove. This makes a Tenant-Admin-lockout scenario
structurally impossible, not just runtime-checked
(`test_built_in_tenant_admin_role_cannot_be_made_unsafe` proves this
directly against a role literally named/slugged `tenant-admin`). Only
custom, admin-created roles support the full create/edit/permission-
assignment flow. No role deletion exists this checkpoint at all — for
any role, system or custom — which is the simplest possible guarantee
that "Tenant Admin role protected from deletion" holds, since there is
no delete path for anything yet.

### `permissions.assign`, not `roles.assign_permission` — the existing catalog entry, wired up

`roles` => `[view, create, update, delete]` and
`permissions` => `[view, assign, grant_direct, revoke_direct]` already
existed in `PermissionSeeder`'s catalog before this checkpoint —
`permissions.assign` was seeded but never used by any code path. This
checkpoint wires it up for exactly the purpose its name already
suggested (assigning a permission — to a role, in this case) rather
than inventing a new `roles.assign_permission` key that doesn't exist
in this app's actual catalog. Tenant Admin already holds it via the
existing "all non-platform permissions" wildcard grant
(`RoleSeeder::seedTenantRoles()`); no other seeded role gained it this
checkpoint.

### Every mutation re-checks tenant/platform/system-role server-side, not just at the request-validation layer

`RoleController`/`RolePermissionController` both call
`ensureBelongsToCurrentTenant()` (404 for platform role or cross-tenant
role — same pattern as `UserController`/`UserRoleController` since
Role, like User, doesn't use `BelongsToTenant`) and, for any mutation,
`ensureNotSystemRole()` (403 — the role genuinely exists and is
viewable, just protected). `AssignRolePermissionRequest`'s
`Rule::exists()` independently rejects a platform-only permission
(422) before the controller even runs — and `Role::givePermissionTo()`'s
existing scope-mismatch guard (`RuntimeException` if
`is_platform_role !== is_platform_permission`) is a third, model-layer
backstop underneath that. A forged request bypassing the frontend
entirely (no UI at all, just a raw HTTP client) still hits all three
layers — proven directly by `RolePermissionApiTest`, which never uses
the frontend, only raw JSON requests.

### Audit logging: a new pair of methods, deliberately separate from the seeder's bulk grant path

`Role::assignPermission()`/`removePermission()` (new) write
`role.permission_assigned`/`role.permission_removed` audit entries;
`Role::givePermissionTo()` (existing, used only by `RoleSeeder`'s bulk
seeding) deliberately does not. Audit-logging every one of
`RoleSeeder`'s roughly 100+ bulk permission grants on every
`migrate:fresh --seed` would flood the audit log with seeding noise
that has nothing to do with a real administrative action — the
distinction here is "was this one deliberate action a human took,"
which the seeder's bulk catalog-building loop never is.

### `RoleResource`'s `permissions` field: eager-loaded only, never a query-time surprise

`index()` never loads the `permissions` relationship, so
`RoleResource`'s `$this->whenLoaded('permissions')` correctly omits
the field there — the list endpoint's response shape is unchanged from
Checkpoint 23. `show()`/`store()`/the permission assign/remove
responses all explicitly eager-load it, so the detail page gets the
full grouped list. No raw pivot table name, no guard/internal
implementation detail, appears in any response — confirmed directly
(`test_role_detail_does_not_expose_raw_pivot_internals`).

### What is not, and cannot be, tested by a JS runner

Same posture as every prior module UI checkpoint — no Jest/Vitest
configured. The "Remove" button only appearing for a custom role, and
the "System roles are protected" message rendering for a system role,
are client-side conditionals this project's test suite can't execute
directly — what's tested instead is the fact the UI's disabling
conditional and every button's underlying action both depend on:
`is_system_role`'s value in the API response, and the API's
independent rejection of the same action regardless of what the UI
shows.

### Current limitations

- No role deletion — for any role, system or custom. A deliberate
  choice this checkpoint (see "Safer MVP" above), not an oversight.
- No direct user permission grants added or changed this checkpoint —
  unchanged from Checkpoint 4/5's existing `grantPermission()`/
  `revokePermission()`, which this feature doesn't touch.
- System roles cannot have their permissions edited at all through this
  UI — including genuinely safe-looking changes (e.g. adding
  `documents.view` to HR Officer). A future checkpoint could allow
  editing non-critical built-in roles' permissions if a real need
  emerges and a careful "would this break the only admin path" check is
  designed — deliberately not attempted this checkpoint.
- No role import/export, no access review workflow, no approval
  workflow for permission changes, no segregation-of-duties engine, no
  permission risk scoring.
- No platform-wide RBAC management — Platform Super Admin gets the
  same safe, blocked-from-tenant-APIs behavior as every other tenant-
  scoped feature (confirmed live: `GET /api/v1/roles` as a platform
  admin returns `403`, unchanged from Checkpoint 23).

### Future

- A genuine access-review/approval workflow for permission changes, if
  compliance requirements ever call for one.
- Allow careful, checked editing of specific non-critical built-in
  roles' permissions, once a real "would this break the only admin
  path" safeguard is designed (see Current limitations above).
- Role deletion, with the same last-admin-path protection
  `TenantAdminProtectionService` already provides for user-level role
  removal (Checkpoint 23), extended to cover "deleting the tenant's
  only role with a given critical permission."

## Employee Lifecycle Foundation (Checkpoint 32)

Adds full CRUD (`view`/`create`/`update`/`delete`) for Departments,
Positions, and Locations — three lookup entities that existed at the
schema level since Checkpoint 6 but had no API, permissions, or UI at
all. See
[`architecture.md`](architecture.md#employee-lifecycle-foundation-checkpoint-32)
for the schema/technical writeup; this section covers the security
design.

### Same three-layer tenant-isolation pattern as every other top-level admin resource, not a new one

`DepartmentController`/`PositionController`/`LocationController` are
structurally identical to `DocumentCategoryController` (Checkpoint 9):
`tenant.matches` middleware → `BelongsToTenant` global scope → an
explicit controller-level tenant-ownership check on every mutation.
No new isolation pattern was introduced for this checkpoint — reusing
an already-tested pattern was preferred over inventing a fourth
variant.

### Permission tiers: four roles, four different trust levels, none broader than approved

| Role | departments/positions/locations |
|---|---|
| Tenant Admin | full (via existing wildcard grant) |
| HR Manager | `view`, `create`, `update`, `delete` |
| HR Officer | `view`, `create`, `update` (no `delete`) |
| Line Manager | `view` only |
| Auditor | `view` only |
| Employee | none |

Employee deliberately receives no direct lookup permission — an
employee sees their own department/position/location only as resolved
names on their own linked employee record (`GET /me/employee`,
Checkpoint 11), never through a standalone `/departments` etc. call.
Checked explicitly before granting: HR Manager is the only
non-Tenant-Admin role holding `employees.create`/`employees.update`,
and it already receives full access to all three lookup entities — so
there is no scenario where a role can create/edit an employee but
can't populate the department/position/location fields, which would
have been a real UI-breaking permission gap if it existed.

### `slug` is never client-writable, at any point

`StoreDepartmentRequest` validates only `name`/`description`;
`UpdateDepartmentRequest` adds `status`. Neither request has a `slug`
rule — a forged request body containing `slug` has it silently
dropped before the controller ever sees it
(`test_forged_slug_in_request_is_ignored`, replicated across all three
entities). The controller always derives the slug server-side from
`name` via `Str::slug()` plus a numeric disambiguation suffix if
needed, scoped `withoutGlobalScopes()` per-tenant so a soft-deleted
row's slug still blocks reuse.

### A real, pre-existing `Rule::exists()` gap in Employee, closed here — the same class of bug Checkpoint 9 already fixed once

`StoreEmployeeRequest`/`UpdateEmployeeRequest`'s `department_id`/
`location_id`/`position_id` validation (since Checkpoint 6) checked
only that the referenced row belonged to the current tenant — it never
excluded archived (`status: inactive`) or soft-deleted rows, because
`Rule::exists()` is a raw database check that bypasses Eloquent's
`SoftDeletes` global scope and any status column entirely. This is
structurally identical to the `document_categories` gap found and
fixed in Checkpoint 9 — the same lesson applied a second time,
deliberately, per your explicit "use the same safe pattern used in
Checkpoint 9" instruction. Fixed by adding
`->where('status', DepartmentStatus::Active->value)->whereNull('deleted_at')`
(and the Position/Location equivalents) to each rule. Verified both
failure modes directly: a soft-deleted department and a merely
`inactive` (not deleted) department are both rejected with a 422 on
`department_id` (`test_archived_department_cannot_be_assigned_to_employee`,
and the Position/Location equivalents).

**This exclusion only applies to *new or changed* assignment, not
retroactively.** An employee already assigned to a department that is
later archived keeps that assignment — `department_id` is `nullable`
with no `sometimes` in the validation rules, so it's only re-validated
when a request actually supplies the field. Confirmed directly
(`test_updating_unrelated_employee_field_does_not_revalidate_an_already_archived_department`):
archiving a department after assignment, then PATCHing an unrelated
employee field (`first_name`), succeeds and leaves `department_id`
unchanged. This is a deliberate consequence of the validation shape,
not an overlooked edge case — retroactively invalidating existing
assignments the moment a lookup entity is archived would be a much
larger behavior change than this checkpoint's approved scope.

### `EmployeeResource`'s new nested objects carry no additional sensitive data

`department`/`location`/`position` each resolve to `{id, name}` (or
`null`) — the same two fields (`id`, `name`) already publicly visible
via the entities' own `GET /departments` etc. endpoints to anyone
holding the corresponding `.view` permission. No new sensitive field is
exposed by this addition; it only avoids a viewer needing bare IDs
resolved through a second manual lookup.

### What was intentionally not built this checkpoint

Per your explicit "do not build yet" list: no employment-type lookup
table (stays the existing enum — see `architecture.md`), no
onboarding/offboarding workflow, no org chart, no payroll/salary/
benefits/grades/cost-centre features, no recruitment, no performance
reviews, no training/LMS, no assets, no attendance/timesheets/shifts,
no disciplinary/case management, no HR service desk. All three lookup
entities are pure CRUD-plus-archive, nothing more.

### Current limitations

- No bulk import/export for departments/positions/locations (contractor/
  vendor bulk import via Excel, added in a separate checkpoint, does
  not extend to these three entities).
- No department/position hierarchy (e.g. sub-departments, reporting
  position levels) — flat lists only.
- No usage-count guard before archiving — archiving a department that
  still has active employees assigned to it is allowed; existing
  assignments are simply left untouched (see "only applies to new or
  changed assignment" above). A future checkpoint could add a warning
  or block if this becomes a real operational problem.
- No cascading effect when a location/department/position is archived
  beyond blocking *new* assignment — no notification, no bulk
  reassignment tool.

### Future

- Department/position hierarchy, if a real tenant need for multi-level
  structures emerges.
- A guard (warning or hard block) before archiving a lookup entity that
  still has active employees assigned, once real usage patterns clarify
  whether this is actually needed.
- Bulk import/export for these three entities, following the same
  Excel-import pattern already built for contractor/vendor records.

## Onboarding & Offboarding Foundation (Checkpoint 33)

Adds a practical workflow foundation for employee onboarding/
offboarding — two new tables (`employee_lifecycle_processes`,
`employee_lifecycle_tasks`), one generic permission set, no template
designer, no approval routing, no notifications. See
[`architecture.md`](architecture.md#onboarding--offboarding-foundation-checkpoint-33)
for the schema/technical writeup; this section covers the security
design.

### Same three-layer tenant-isolation pattern as every other top-level module

`LifecycleProcessController`/`LifecycleTaskController` follow the exact
shape established since Checkpoint 6: `tenant.matches` middleware →
`BelongsToTenant` global scope → an explicit controller-level
tenant-ownership check on every action. No new isolation pattern was
introduced.

### Permission model: one generic set, six roles, three distinct visibility tiers

`lifecycle.view`/`create`/`update`/`delete`/`assign_task`/`complete_task` —
per your explicit "simpler generic" recommendation over an
`onboarding.*`/`offboarding.*` split, since no role in the approved
grant table actually needs different access to one process type vs.
the other.

| Role | view | create | update | delete | assign_task | complete_task |
|---|---|---|---|---|---|---|
| Tenant Admin | ✓ (wildcard) | ✓ | ✓ | ✓ | ✓ | ✓ |
| HR Manager | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| HR Officer | ✓ | ✓ | ✓ | — | ✓ | ✓ |
| Line Manager | ✓ | — | — | — | — | ✓ |
| Auditor | ✓ | — | — | — | — | — |
| Employee | ✓ | — | — | — | — | ✓ |

**A real design problem this permission table creates, solved
deliberately, not accidentally: Line Manager and Employee hold the
*identical* set** (`view` + `complete_task`, nothing else) despite
needing different visibility — a Line Manager should see their direct
reports' processes; an Employee should see only tasks assigned to
them. No permission key distinguishes these two roles here, unlike
Leave Management's `leave.view`/`leave.view_team`/`leave.view_all`
three-tier split. `LifecycleVisibilityService::hasUnrestrictedAccess()`
resolves the ambiguity from relationship data instead:

1. **Holding any write permission** (`create`/`update`/`delete`/
   `assign_task`) on this resource → HR/Admin tier, sees and can act on
   everything in the tenant.
2. **Holding `view` but not `complete_task` at all** → Auditor tier,
   read-only, sees everything in the tenant (matches how Auditor
   already gets tenant-wide read access to Departments/Positions/
   Locations/Leave elsewhere in this app).
3. **Holding `view` + `complete_task`, nothing else** → the narrowed
   tier (Line Manager or Employee). Scoped to: processes for the
   caller's own direct reports (via the existing, already-tested
   `ManagerHierarchyService::directReportsOf()`, unchanged since
   Checkpoint 14) and/or any process containing a task assigned
   directly to the caller.

This is a genuine judgment call, not something derivable purely from
the approved permission list — flagged explicitly rather than decided
silently. The practical effect: a Line Manager can see and complete
tasks within their direct report's onboarding/offboarding process even
if a specific task isn't literally assigned to them (e.g., "introduce
new hire to the team"), while an Employee with no direct reports sees
only tasks where they are the literal assignee.

### Two genuine, identically-shaped permission gaps found and fixed mid-checkpoint, both flagged before deciding

Building the Create-process form's employee picker surfaced that
`GET /api/v1/employees` requires `employees.view`, which HR Officer
never held (only `employees.view_team`) — despite this checkpoint
granting HR Officer `lifecycle.create`. Building the task
assignee picker surfaced the identical shape: `GET /api/v1/users`
requires `users.view`, also never held by HR Officer, despite holding
`lifecycle.assign_task`. Both are the same class of gap Checkpoint 19
found for `document_categories.view` — a role granted an action but
not the read permission its own UI depends on. Both were confirmed with
you individually (not assumed from the first approval alone, since
`users.view` exposes a broader, more sensitive resource — the full
tenant user/account list — than `employees.view`) before granting.
Both fixes are view-only; no create/update/deactivate/assign_role was
added to either grant.

### `lifecycle.assign_task` is a sub-permission of create/update, not a separate route

Setting or changing a task's `assigned_to_user_id` requires
`lifecycle.assign_task` in addition to `lifecycle.create`/
`lifecycle.update` — checked explicitly in the controller
(`abort_if(..., ! $request->user()->hasPermission('lifecycle.assign_task'))`),
not folded into the general update permission. Every role holding
`create` in this checkpoint's approved grants also holds `assign_task`,
so this has no visible effect on the seeded demo roles today — it
exists so a future custom role that separates "can add tasks" from
"can decide who does them" is already safe.

### Status transitions are centralized and validated against current state, not just enum membership

`LifecycleProcessStatus`/`LifecycleTaskStatus` each carry
`allowedNextStates()`/`canTransitionTo()` — the exact pattern
`LeaveRequestStatus` established in Checkpoint 12. Checked in
`UpdateLifecycleProcessRequest`/`UpdateLifecycleTaskRequest`'s
`withValidator()` against the route-bound record's *current* status.
A terminal process (`completed`/`cancelled`) or task (`completed`/
`skipped`) rejects every further mutation outright (422) — per your
explicit rule 9, not just illegal-transition attempts.

**The generic `PATCH` task endpoint cannot be used to set `completed`/
`skipped` directly.** Only `pending`/`in_progress` are accepted through
`UpdateLifecycleTaskRequest`'s `status` field — reaching a terminal
state requires the dedicated `POST .../complete` or `.../skip` action,
which are the only code paths that set `completed_at`/`completed_by`
from trusted context. Verified directly
(`test_update_endpoint_cannot_be_used_to_set_completed_status_directly`).

### Task assignee must belong to the same tenant, same pattern as every other tenant-scoped reference field

`assigned_to_user_id` is validated via `Rule::exists('users', 'id')`
scoped to the current tenant, excluding platform admins and inactive
users — the same shape as `LinkEmployeeUserRequest`'s `user_id` rule
(Checkpoint 11) and `StorePolicyRequest`'s `owner_user_id` rule
(Checkpoint 10).

### Deletion is soft-cancel/soft-delete, never hard, for both processes and tasks

`DELETE /lifecycle-processes/{process}` transitions a non-terminal
process to `cancelled` before soft-deleting it; an already-terminal
process is simply hidden, its status left untouched (there's no
truthful "cancel" action for something already completed).
`DELETE /lifecycle-tasks/{task}` soft-deletes only, logged as
`lifecycle_task.deleted` — an audit action name not in your originally
listed set, added anyway since under-logging a real mutation is a
worse failure mode than slightly exceeding the minimum required list.

### Audit metadata never carries free-text task content

Only `id`/`status`/`process_id`/`assigned_to_user_id` are ever passed
to `AuditLogger::logFor()` as metadata for task events — `title`/
`description` are never included, per your explicit "do not log
sensitive free-text task details if avoidable" instruction. This is
stricter than `AuditLogger`'s own mask-by-key-pattern fallback (which
only catches a field if its *name* matches a known-sensitive
substring): here the free-text fields are simply never passed through
at all. Verified directly
(`test_task_description_is_not_stored_in_audit_metadata`).

### No standalone single-task read endpoint

The approved API route list has no `GET /api/v1/lifecycle-tasks/{task}` —
the Task Edit page instead fetches the parent process (which already
eager-loads its tasks) and finds the specific task client-side by ID.
Adding a new route solely to avoid one client-side lookup would have
exceeded the approved "keep it minimal" scope.

### Current limitations

- No task templates/reusable checklists (`lifecycle_task_templates`
  was offered as optional in the proposed schema and deliberately not
  built this checkpoint — HR adds tasks directly per process).
- ~~No task dependencies, ordering, or approval routing.~~ Manual
  ordering (`sort_order`, plus drag-and-drop reordering) was added in
  Checkpoint 45. Task **dependencies**/blocking and approval routing
  are still not built — Checkpoint 45 deliberately scoped "ordering" as
  display order only, not a prerequisite graph.
- ~~No notifications, email reminders, or calendar integration for due
  dates.~~ A daily email digest of overdue/due-soon tasks was added in
  Checkpoint 45 (`lifecycle:send-task-digest`). Calendar integration is
  still not built.
- No IT/asset provisioning integration, no document generation, no
  e-signature.
- No recruitment-to-employee conversion — a lifecycle process must be
  started manually for an existing employee record.
- No performance/probation review integration.
- Indirect (skip-level) manager visibility is out of scope — Line
  Manager sees only direct reports' processes, the same "direct only"
  policy decision Leave Management made in Checkpoint 14.

### Future

- Task templates/checklists, once real onboarding/offboarding patterns
  across tenants clarify what's actually reusable.
- A workflow designer, if a real need for configurable multi-step
  approval routing emerges — explicitly out of scope for this
  foundation.
- ~~Notifications/reminders for upcoming or overdue tasks.~~ Done in
  Checkpoint 45.
- Integration points for IT provisioning, asset assignment, and
  document generation once those modules exist.

## HR Documents & Letter Generation Foundation (Checkpoint 34)

Adds a foundation for HR-generated employee letters/documents — two
new tables (`hr_document_templates`, `hr_generated_documents`),
content-only (Option A, your explicit approved choice: no PDF/DOCX
file, `rendered_content` stored as plain text). See
[`architecture.md`](architecture.md#hr-documents--letter-generation-foundation-checkpoint-34)
for the schema/technical writeup; this section covers the security
design.

### Same three-layer tenant-isolation pattern as every other top-level module

`HrDocumentTemplateController`/`HrGeneratedDocumentController` follow
the exact shape established since Checkpoint 6: `tenant.matches`
middleware → `BelongsToTenant` global scope → an explicit
controller-level tenant-ownership check on every action
(`ensureBelongsToCurrentTenant()`). Generation additionally validates
both `employee_id` and `hr_document_template_id` against the current
tenant at the FormRequest layer (`Rule::exists(...)->where('tenant_id', ...)`)
*and* re-checks both in the controller after `findOrFail()` — the same
two-layer belt-and-suspenders pattern every tenant-scoped write in this
app uses, not a new one invented here.

### Permission model: two resources, five roles with real access, HR Director added deliberately

```
hr_document_templates.view / .create / .update / .delete
hr_generated_documents.view / .create / .update / .delete / .generate
```

`.create` on `hr_generated_documents` is seeded but not wired to any
route this checkpoint — the only write path is `.generate`, which both
creates and renders in one step (see `architecture.md`). Split, rather
than a single umbrella `hr_documents.*` set, per your explicit
suggested "cleaner split" — templates and generated documents carry
different trust levels (editing a template shapes every future
letter; generating one only affects a single employee's record).

| Role | templates.view | templates.create/update/delete | documents.view | documents.create/update/generate | documents.delete |
|---|---|---|---|---|---|
| Tenant Admin | ✓ (wildcard) | ✓ | ✓ | ✓ | ✓ |
| HR Manager | ✓ | ✓ | ✓ | ✓ | ✓ |
| HR Director | ✓ | ✓ | ✓ | ✓ | ✓ |
| HR Officer | ✓ | — | ✓ | ✓ | — |
| Auditor | ✓ | — | ✓ | — | — |
| Line Manager | — | — | — | — | — |
| Employee | — | — | — | — | — |

**HR Director previously held no permissions anywhere** (a placeholder
role since Checkpoint 4, per the class-level note in `RoleSeeder`).
Per your explicit approval, it receives the identical HR document
grant as HR Manager for this checkpoint only — every other module
stays untouched/empty for this role, so this is a scoped exception, not
a general "activate HR Director" decision.

**Line Manager and Employee get no access by default**, per your
explicit instruction — this is a deliberate, narrower default than
most other modules in this app grant those two roles, reflecting that
HR letters are an HR-administrative function, not a self-service or
team-management one, unlike Leave or Lifecycle.

### Placeholder rendering: an allowlist substitution, never a template engine

`App\Services\HrDocuments\PlaceholderRenderer::render()` is the entire
attack surface for template content. It calls PHP's `strtr()` exactly
once, with a fixed array of exactly ten keys
(`{{employee.name}}`, `{{employee.employee_number}}`, `{{employee.email}}`,
`{{employee.department}}`, `{{employee.position}}`, `{{employee.location}}`,
`{{employee.employment_type}}`, `{{employee.start_date}}`,
`{{tenant.name}}`, `{{today}}`) mapped to real, already-resolved scalar
values. There is no code path from stored `content_template` text to:

- Blade compilation or any other template-engine execution.
- `eval()` or dynamic code execution of any kind.
- Reflection or dynamic property/method access driven by the template
  string (the map's keys are a hardcoded PHP array literal, never
  derived from the input).
- Raw HTML rendering — `rendered_content` is displayed in React as
  `{content}` (escaped by React) or a Laravel `<textarea>`/plain-text
  field, never `dangerouslySetInnerHTML`, the exact rule Checkpoint 20
  established for Policy content.

**An unknown or malformed token is left completely unchanged** — not
executed, not an error, not silently stripped. `strtr()`'s array form
only matches the exact keys given; anything else (a typo, wrong
casing, an attacker-supplied `{{system.env.APP_KEY}}` or
`{{employee.delete()}}`) simply isn't in the map and passes through
verbatim. See `tests/Unit/PlaceholderRendererTest.php` for the exact
cases exercised: allowed substitution, unknown tokens, near-miss
casing, and null department/position/location relations rendering as
an empty string (never `null`, never a PHP notice).

### Audit logging never carries the rendered letter's full content

`hr_generated_document.generated`/`.updated`/`.archived` log only
`employee_id`, `hr_document_template_id`, `document_type`, and `title`
as metadata — `rendered_content` is never passed to
`AuditLogger::logFor()` at all, per your explicit "do not log full
letter content if avoidable" instruction. This is the same "never pass
the free-text field to the logger" discipline Checkpoint 33 applied to
lifecycle task descriptions — stricter than `AuditLogger`'s own
mask-by-field-name fallback, which would only catch a field if its
*name* happened to match a known-sensitive substring.

### Update is title-only — a status transition can never be smuggled through it

`UpdateHrDocumentTemplateRequest` accepts `status`, but
`UpdateHrGeneratedDocumentRequest` accepts `title` only — a generated
document's `status`/`rendered_content`/`generated_by`/`tenant_id` are
never accepted from request input on any route, at any point after
generation. Archiving is a dedicated `DELETE` (soft-delete) action on
both resources, the same `DocumentCategoryController::destroy()`
pattern from Checkpoint 9/25 — not a generic status field a client
could set to any value.

### Content-only was a deliberate dependency decision, not a silent scope cut

No PDF (`dompdf`/`mpdf`/Spatie Laravel-PDF/etc.) or DOCX
(`PHPOffice/PHPWord`/etc.) library existed in `composer.json`/
`package.json` before this checkpoint — verified directly during the
gap analysis, not assumed. Rather than add one inline, the choice was
flagged as a separate dependency decision and you approved Option A
(content-only) for this checkpoint. `employee_document_id` on
`hr_generated_documents` stays nullable and unused — the same
forward-compatible-placeholder shape `policy_versions.employee_document_id`
already established in Checkpoint 20 for an analogous "no real file
yet" gap — so a future checkpoint that adds real PDF generation can
populate it without a schema change.

### Current limitations

- No PDF or DOCX file generation — `rendered_content` is plain text in
  the database only; `employee_document_id` stays `null` on every row
  this checkpoint.
- No e-signature, no approval/review workflow before a document is
  considered final — generation is immediate and final (`status`
  becomes `generated` in the same request).
- No automated sending/email delivery, no external sharing links.
- No template versioning — editing a template's `content_template`
  changes it in place; already-generated documents keep their
  `rendered_content` as rendered at the time, but there is no history
  of prior template revisions the way `PolicyVersion` keeps for policies.
- No bulk generation (one employee per generation request) and no
  employee self-service download — generation and viewing are both
  HR-administrative actions in this checkpoint.
- No notifications when a document is generated.

### Future

- PDF (and/or DOCX) file generation, once a specific library is
  separately reviewed and approved as its own dependency decision —
  attaching the resulting file via the existing (currently unused)
  `employee_document_id` column, no schema change needed.
- Template versioning, if template content actually needs a
  publish/draft history the way policies do — deliberately not built
  this checkpoint per your "keep template versioning out unless very
  easy and safe" instruction.
- E-signature and approval-routing workflows, once a real
  legal/compliance need is scoped.
- Bulk generation (e.g., generate the same letter for a department) and
  employee self-service download, once real demand is shown.

## PDF Export Dependency Review & Prototype (Checkpoint 35)

Adds on-demand PDF download for HR generated documents —
`dompdf/dompdf`, Option B (generate, never store), both approved after
a dedicated dependency/environment review before any code was written.
See [`architecture.md`](architecture.md#pdf-export-dependency-review--prototype-checkpoint-35)
for the technical writeup; this section covers the security design.

### Same three-layer tenant-isolation pattern, no new permission

`HrGeneratedDocumentController::downloadPdf()` follows the identical
shape every other action on this controller already uses: `tenant.matches`
middleware → `BelongsToTenant` global scope → the controller's explicit
`ensureBelongsToCurrentTenant()` check. It is gated by the existing
`hr_generated_documents.view` permission, not a new one — a PDF is just
another rendering of data the caller can already see in JSON via
`GET .../{id}`, so no new permission key was introduced for it, the
same reasoning already applied to `policies.view` covering the
read-only `GET .../versions` endpoint in Checkpoint 20.

### The PDF pipeline is entirely code-owned HTML — no user/HR-authored markup ever reaches dompdf

`HrDocumentPdfRenderer::render()` builds a fixed HTML string itself;
every value it interpolates (`title`, employee full name, tenant name,
formatted `generated_at`, and `rendered_content`) is passed through
`e()` first. `rendered_content` — the one field that ultimately
originated from an HR-authored `content_template` plus employee data —
is additionally run through `nl2br()`, but only *after* escaping, so a
line break renders as a real `<br>` without ever opening a path for
injected markup. This is the same "never trust content as markup" rule
the frontend already applies (no `dangerouslySetInnerHTML`, established
for Policy content in Checkpoint 20 and reused for HR document content
in Checkpoint 34) — extended here to the one other place this content
gets rendered.

### dompdf's own risk surfaces are explicitly disabled, not relied upon by default

- `Options::setIsRemoteEnabled(false)` — dompdf's own default, set
  explicitly rather than trusted implicitly, so a future dompdf upgrade
  changing its default would not silently reopen this. Prevents any
  `<img src="https://...">`/`@import url(...)`-style remote fetch
  from a future template — no SSRF path from template content to an
  internal or external URL.
- `Options::setIsJavascriptEnabled(false)` — dompdf's limited JS subset
  (used by some templates for dynamic page numbering) is never needed
  here and is turned off explicitly.
- `Options::setChroot(sys_get_temp_dir())` restricts any local file
  access dompdf's rendering internals might attempt to a harmless
  temp directory, never the application's own source tree.

### Option B means there is no new storage path to secure at all

Nothing is written to `Storage::disk(...)` — `HrDocumentPdfRenderer::render()`
returns raw PDF bytes, and the controller streams them directly in the
HTTP response. There is no file path to leak in a response, log, or
error message, because no file exists past the single request/response
cycle. This is a stronger privacy guarantee than "the file lives on a
private disk" (Checkpoint 8's pattern for `employee_documents`) — there
is no disk involved at all. `hr_generated_documents.employee_document_id`
remains unused, unchanged since Checkpoint 34, reserved for a future
Option C that would reuse the exact private `local` disk pattern
`EmployeeDocumentController` already established.

### Why headless-browser PDF generation was rejected before it was ever tried

`spatie/browsershot` (headless Chrome) and `wkhtmltopdf`/Snappy were
compared and ruled out at the dependency-review stage: both require
installing and keeping patched a browser or standalone binary on the
server, which is workable on a fully-controlled VPS but incompatible
with the cheap shared hosting `docs/quality-gate.md` already treats as
a real constraint — most shared PHP hosts have no shell access to
install one at all. `dompdf/dompdf` is pure PHP with no such
requirement, and a single-page text letter has no layout complexity
that would justify the added attack surface and operational cost of a
full browser engine.

### Current limitations

- No PDF is ever stored — every download re-renders from `rendered_content`,
  which is fine for a single-page letter with no images but would be
  wasteful for a much larger document. Not a concern at this content
  scale.
- No DOCX export (still out of scope, unchanged from Checkpoint 34).
- No e-signature, approval workflow, automated sending, external
  sharing, template versioning, or bulk generation (all unchanged from
  Checkpoint 34's stated limitations).
- The rendered PDF has no header/footer branding, page numbers, or
  letterhead — plain title + metadata line + content, matching the
  content-only spirit of this feature so far.

### Future

- Option C (generate once, persist to the private `local` disk, attach
  via `hr_generated_documents.employee_document_id`) if a real need for
  re-downloading identical bytes without re-rendering, or for
  attaching the PDF to the broader Document Repository, is shown.
- Letterhead/branding, page numbers, and a footer, once a real design
  requirement exists — the current plain layout was a deliberate
  minimum, not a placeholder.
- DOCX export, if a real customer need for an editable format
  (as opposed to a final, non-editable letter) is shown.

## HR Document Template Versioning Foundation (Checkpoint 36)

Adds real version history to HR document templates — a new
`hr_document_template_versions` table, per your approved minimal
schema (template-only metadata, versioned `content_template` only). See
[`architecture.md`](architecture.md#hr-document-template-versioning-foundation-checkpoint-36)
for the schema/technical writeup; this section covers the security
design.

### Same three-layer tenant-isolation pattern as every other module

`HrDocumentTemplateVersionController` follows the exact shape
established since Checkpoint 6: `tenant.matches` middleware →
`BelongsToTenant` global scope → an explicit controller-level
tenant-ownership check on every action. Because `BelongsToTenant`'s
global scope filters *every* query — including implicit route-model
binding — a cross-tenant version ID 404s before a FormRequest's
`withValidator()` ever runs against it, so
`UpdateHrDocumentTemplateVersionRequest`'s draft-status check can never
leak information about another tenant's version.

### Permission model: one new key, everything else reused

```
hr_document_templates.view    — list/view versions (existing)
hr_document_templates.update  — create a draft version, edit a draft (existing)
hr_document_templates.publish — publish a version (new, Checkpoint 36)
hr_document_templates.delete  — delete a draft-only version (existing)
```

A version is the template's own history, not a separate-trust
resource — reusing the existing template permission set (per your
explicit preference) rather than inventing `hr_document_template_versions.*`.
`.publish` is the one new key, mirroring `policies.publish` alongside
`policies.update`.

| Role | view/create/update draft | publish | delete draft |
|---|---|---|---|
| Tenant Admin | ✓ (wildcard) | ✓ | ✓ |
| HR Manager | ✓ | ✓ | ✓ |
| HR Director | ✓ | ✓ | ✓ |
| HR Officer | view only, no manage | — | — |
| Auditor | view only | — | — |
| Line Manager / Employee | — | — | — |

HR Manager and HR Director both gain `.publish` alongside their
existing full template-manage grant — consistent with them already
holding every other template permission this checkpoint reuses. HR
Officer/Auditor/Line Manager/Employee are unchanged from Checkpoint 34
— HR Officer still never manages templates at all (view-only), so it
gets no version-management or publish access either.

### Draft-editable, published-immutable, by design — not by accident

`UpdateHrDocumentTemplateVersionRequest::withValidator()` rejects (422)
any edit unless the route-bound version's `status` is currently
`draft` — a published or archived version's `content_template` is
permanent from that point on. This is the one place this checkpoint
goes beyond `PolicyVersion`'s precedent (which has no edit endpoint for
version content at all, ever) — your explicit requirement that HR/Admin
users be able to revise a draft before publishing it.

### Publishing is a dedicated, minimal action — never a generic status field

`published_at`/`published_by` are set only inside
`HrDocumentTemplateVersionController::publish()`, server-side, in the
same request that demotes the previously-published version (if any) to
`archived`. Neither field is ever accepted from request input — tested
directly (`test_user_with_permission_can_publish_a_draft_version`
submits forged `published_at`/`published_by` values and asserts they're
ignored). `hr_document_templates.current_version_id` is updated in the
same request, so a template's "what's live right now" pointer and its
version history's "what's published right now" flag can never disagree.

### Old versions are never deleted — draft-only deletion is the one exception

`DELETE /api/v1/hr-document-template-versions/{id}` soft-deletes only
when the version's `status` is `draft` (422 otherwise) — an abandoned
draft nothing has ever referenced is safe to discard, but anything that
was ever `published` (now `archived`) is permanent history, per your
explicit "do not delete old versions" rule. `version_number`'s
auto-increment (`max('version_number')`, `withTrashed()`) accounts for
a deleted draft's number never being reused, so the
`unique(tenant_id, hr_document_template_id, version_number)` constraint
is never at risk even after a discard.

### Generation resolves the published version, with defense in depth

`GenerateHrDocumentRequest` requires `hr_document_templates.current_version_id`
to be non-null (in addition to the existing active-status check);
`HrGeneratedDocumentController::store()` re-checks that the resolved
version is genuinely `published` before rendering — the same two-layer
"FormRequest necessary, controller re-verifies" pattern every other
tenant-scoped write in this app uses, here guarding the race where a
version is archived between validation and the render call.

### PDF export is completely unaffected — by construction, not luck

`HrDocumentPdfRenderer` (Checkpoint 35) only ever reads
`hr_generated_documents.rendered_content` — never the live template or
any version. Since this checkpoint doesn't touch `rendered_content` or
the PDF renderer at all, every existing PDF-download test from
Checkpoint 35 still passes unchanged, and a newly generated document's
PDF is identical whether its template has one version or fifty.

### Migration/backfill is verified directly, not just assumed correct

`docs/testing.md` documents rolling back the backfill + column-drop
migrations, inserting a raw pre-Checkpoint-36-shaped row (a template
with `content_template` set directly, no `current_version_id`) via the
query builder, replaying the migrations forward, and confirming: the
template gets a `published` version 1 with matching content, a
generated document referencing that template gets its
`hr_document_template_version_id` backfilled to the same version, and
`content_template` is genuinely gone from `hr_document_templates`
afterward. This is stronger verification than a fresh `migrate:fresh --seed`
alone provides, since this app's demo seed data deliberately contains
no HR document templates (Checkpoint 34's "no demo data pre-seeded"
choice) — a fresh install alone would exercise the backfill migration
over zero rows.

### Current limitations

- No diff/comparison UI between versions — the Versions list shows
  version number, status, and created date, not a wording diff.
- No approval workflow before publishing — any user holding
  `hr_document_templates.publish` can publish immediately; no
  second-reviewer step exists.
- Publishing an old archived version back (a rollback) is *possible*
  (no status guard on the target) but has no dedicated "rollback" UI
  affordance — it's the same Publish button, which is intentionally
  simple rather than surfacing a separate, more explicit rollback
  action.

### Future

- A diff/compare view between two versions, once real demand for it is
  shown — explicitly out of scope for this foundation, per your
  instruction.
- An approval/review step before publishing, if a real
  compliance/legal-review need is scoped — a distinct future decision
  from this checkpoint's plain publish action.
- Option C PDF generation (Checkpoint 35's future work) would attach to
  a specific `hr_document_template_version_id` implicitly via the
  generated document it's derived from — no new design needed there.

## HR Document Approval Workflow Foundation (Checkpoint 37)

Adds a single-approver approval workflow for HR generated documents —
`draft → pending_approval → approved | rejected`, with `archived`
reachable from any non-terminal status. See
[`architecture.md`](architecture.md#hr-document-approval-workflow-foundation-checkpoint-37)
for the schema/technical writeup; this section covers the security
design.

### Same three-layer tenant-isolation pattern, transitions centrally guarded

`submit()`/`approve()`/`reject()` on `HrGeneratedDocumentController`
follow the identical shape every other action on this controller
already uses: `tenant.matches` middleware → `BelongsToTenant` global
scope → the controller's explicit `ensureBelongsToCurrentTenant()`
check. Every transition is checked against
`HrGeneratedDocumentStatus::canTransitionTo()` before anything is
written — an invalid transition (approving a draft, rejecting an
already-approved document, submitting an archived one) is rejected
(422), never silently coerced or partially applied.

### Permission model: three new keys, deliberately not folded into `.update`

```
hr_generated_documents.submit  — draft/rejected -> pending_approval (new)
hr_generated_documents.approve — pending_approval -> approved (new)
hr_generated_documents.reject  — pending_approval -> rejected (new)
```

| Role | submit | approve | reject |
|---|---|---|---|
| Tenant Admin | ✓ (wildcard) | ✓ | ✓ |
| HR Manager | ✓ | ✓ | ✓ |
| HR Director | ✓ | ✓ | ✓ |
| HR Officer | ✓ | — | — |
| Auditor | — | — | — |
| Line Manager / Employee | — | — | — |

The split is the entire point: HR Officer can generate and submit a
letter but can never approve or reject one, including its own —
self-approval is structurally impossible (no permission grant makes it
possible), not merely discouraged by UI convention. Auditor keeps its
existing view-only access (no new grant needed — `rejection_reason`
and the approval timeline are already visible via the existing
`hr_generated_documents.view`-gated resource).

### Server-controlled fields, verified directly

`submitted_at`/`submitted_by`/`approved_at`/`approved_by`/`rejected_at`/`rejected_by`
are set only inside their respective controller action, from `now()`
and `$request->user()->id` — never accepted from request input. Tested
directly: both `submit()` and `approve()` tests submit forged
`submitted_at`/`approved_at`/`approved_by` values in the request body
and assert they're silently ignored in favor of the real server-set
values.

### Editability is a status gate, not a new endpoint

`UpdateHrGeneratedDocumentRequest::withValidator()` rejects (422) a
title edit unless the route-bound document is currently `draft` or
`rejected` — the same "checked in withValidator() against the
route-bound record's current status" pattern
`UpdateHrDocumentTemplateVersionRequest` already established in
Checkpoint 36. A `pending_approval` or `approved` document's title is
permanent from that point until either rejected (revisable again) or
archived.

### Rejection reason: stored and shown, never audited

`rejection_reason` is real, persisted, plain text — exposed on
`HrGeneratedDocumentResource` (the entire purpose of rejecting is that
the submitter can see why; masking it from the API would defeat the
feature) but deliberately never passed to `AuditLogger::logFor()` as
metadata for the `.rejected` action, the same "free-text content never
reaches the logger" rule already applied to lifecycle task descriptions
and this module's own `rendered_content`. Tested directly: a marker
string placed in `rejection_reason` is asserted present in the resource
response and absent from the resulting audit log row.

### PDF watermark: Option A (approved) — preview allowed at every status, clearly labeled when not final

`HrDocumentPdfRenderer` adds a plain-text banner (no images, nothing
resembling an official seal) whenever a document's status isn't
`approved` — "DRAFT — NOT YET SUBMITTED FOR APPROVAL", "PENDING
APPROVAL — NOT YET APPROVED", "REJECTED — NOT APPROVED", or "ARCHIVED".
No new permission or route was introduced — the existing
`hr_generated_documents.view`-gated download works identically
regardless of status; only the rendered bytes differ. This preserves a
genuinely useful HR workflow (preview the actual PDF layout before
submitting or after a rejection) while making an unapproved letter
visually impossible to mistake for a final, approved one.

### Archiving stays unconditional, including mid-review

`destroy()` (soft-delete-as-archive) is reachable from every
non-terminal status, including `pending_approval` — matching this
controller's behavior before this checkpoint (there was never a status
guard on archiving at all) rather than introducing a new blocking rule
nobody asked for. `archived` is genuinely terminal now, though: unlike
before, `destroy()` actually writes `status: archived` to the row (not
just `deleted_at`) before soft-deleting, so the status column now
accurately reflects reality — a small, deliberate correction alongside
the new workflow, not a new capability.

### Migration/backfill is verified directly, the same rigor as Checkpoint 36's version backfill

`2026_07_06_193100_backfill_hr_generated_document_approval_status.php`
maps every pre-existing `generated` row to `approved`, with
`approved_at`/`approved_by` copied from `generated_at`/`generated_by` —
the closest accurate reading of "already finalized" under the old
content-only model (your approved choice over resetting them to
`draft`, which would have forced HR to retroactively re-approve
documents that were never meant to require it). `submitted_at`/`submitted_by`
stay null — fabricating a submission that never happened would be
worse than leaving it absent. Verified by rolling back the migration,
inserting a raw pre-Checkpoint-37 `generated` row via the query
builder, replaying it forward, and confirming the resulting
`approved`/`approved_at`/`approved_by` values match `generated_at`/`generated_by`
exactly and `submitted_at` stays null — see `docs/testing.md`.

### Current limitations

- Single-approver only — no multi-level/routing approval chain, per
  your explicit "do not build multi-level approvals" instruction.
- No approval-routing designer, email reminders, or notifications when
  a document changes state.
- No e-signature, external sharing, or legal review workflow.
- The PDF watermark is a plain-text banner, not a cryptographic or
  visual seal — sufficient to prevent an unapproved letter from being
  mistaken for final by a human reader, not a tamper-evidence mechanism.

### Future

- Multi-level/routing approval, if a real organizational need for more
  than one approver is shown — explicitly out of scope for this
  foundation.
- Notifications/reminders when a document is submitted, approved, or
  rejected.
- E-signature and external sharing, once scoped as their own
  checkpoints.

## HR Document Template Library & Starter Templates (Checkpoint 38)

Adds 8 seeded starter templates and a "Duplicate Template" action for
HR document templates. See
[`architecture.md`](architecture.md#hr-document-template-library--starter-templates-checkpoint-38)
for the schema/technical writeup; this section covers the security
design.

### Starter templates are ordinary tenant data, not a special-cased library

There is no global template table, no cross-tenant sharing, and no new
access-control surface — a starter template is a real
`HrDocumentTemplate`/`HrDocumentTemplateVersion` row, tenant-scoped by
`BelongsToTenant` exactly like every other template. This was the whole
point of choosing Option A over Option B: a global library would need
its own read-access rules (can every tenant see every other tenant's
"public" templates? who curates them? can they be edited centrally?) —
none of those questions exist here, because nothing is actually shared.

### Duplication requires the same permission as creating a blank template

`POST /api/v1/hr-document-templates/{id}/duplicate` is gated by
`hr_document_templates.create` — the same permission every existing
template-create path already requires. No new permission key was
introduced. This means the exact same set of roles that can create a
template today (Tenant Admin, HR Manager, HR Director) can duplicate
one; HR Officer (view-only on templates) is correctly blocked from
duplicating, same as it's already blocked from creating.

### Same three-layer tenant-isolation pattern, plus a real content check

`duplicate()` follows the identical shape every action on this
controller already uses: `tenant.matches` middleware → `BelongsToTenant`
global scope → the controller's explicit `ensureBelongsToCurrentTenant()`
check — a cross-tenant template ID 404s before any copying happens.
Additionally, `duplicate()` requires the source template to have a
`current_version_id` (a real published version to copy) — a template
that's `active` but has never been published (a real, testable edge
case) is rejected (422) rather than silently creating an empty
duplicate.

### Audit logging never carries the copied wording

`hr_document_template.duplicated` logs `source_template_id` and the new
template's own safe metadata (`title`/`slug`/`document_type`/`status`)
— never the `content_template` text that was actually copied, the same
"no full free-text content in audit metadata" rule every other HR
document audit action already follows.

### Starter template content: allowlist-only, no legal/tax/payroll specificity

Every seeded starter template's wording uses only the 10 approved
placeholder tokens and deliberately generic, professional language —
no jurisdiction-specific legal claims, no payroll/tax promises, no
wording that could be mistaken for actual legal advice. Verified
directly, not just written carefully:
`DemoDataSeederTest::test_demo_data_seeds_successfully_with_expected_coverage()`
scans every seeded template's `content_template` for any `{{...}}`
token and asserts it's one of the 10 allowlisted ones — the same
regex-based check `PlaceholderRendererTest` already established for the
rendering engine itself, applied here to the seed data.

### Current limitations

- No global/cross-tenant template library — a new tenant does not
  automatically receive starter templates; only the seeded `uesl` demo
  tenant does, via `DemoDataSeeder`, not a general tenant-provisioning
  hook.
- No template categories/tags beyond the existing `document_type`
  classification, and no "based on starter template X" lineage tracking
  — a duplicate's only link back to its source is the one-time audit
  log entry (`source_template_id`), not a persisted foreign key on the
  template itself.
- No AI-assisted template generation, legal clause library, template
  marketplace, rating system, or import/export.

### Future

- A genuine global/shared template library (Option B), if real demand
  from multiple tenants wanting the same curated starting set is shown
  — would need its own access-control design (who can read/write shared
  templates, whether tenants can only copy or also see updates)
  deliberately not designed here.
- Automatic starter-template seeding for newly-created tenants, once a
  real tenant-provisioning flow exists to hook into.
- AI-assisted drafting and a legal-review workflow, both explicitly out
  of scope for this checkpoint.

## Recruitment & Applicant Tracking Foundation (Checkpoint 39)

Adds job openings, applicants/applications, a pipeline stage, internal
notes, and a "ready for conversion" milestone flag. See
[`architecture.md`](architecture.md#recruitment--applicant-tracking-foundation-checkpoint-39)
for the schema/technical writeup; this section covers the security
design.

### Standard three-layer tenant isolation, no exceptions

Every `job-openings`/`job-applications` route sits inside the same
`auth` → `tenant.matches` → `permission:{key}` middleware stack every
other route in this app uses, `RecruitmentJob`/`RecruitmentApplicant`/
`RecruitmentApplication`/`RecruitmentApplicationNote` all use
`BelongsToTenant`, and every controller action additionally calls its
own `ensureBelongsToCurrentTenant()` (404, not 403, on a cross-tenant
ID) as defense-in-depth beyond the global scope — identical shape to
`LifecycleProcessController`/`HrDocumentTemplateController`. A Platform
Super Admin is blocked the same way they're blocked everywhere else:
`job_openings.*`/`job_applications.*` are tenant-scoped permissions a
platform-admin user can never hold (see `HasPermissions`'s
assignment-scope guard), so the `permission:{key}` middleware alone
already rejects them before any controller code runs.

### Split permissions, narrow write actions

`job_openings.view/create/update/delete` and
`job_applications.view/create/update/delete/update_stage/add_note/mark_ready_for_conversion`
— two separate permission categories (not one generic `recruitment.*`
key), per your explicit recommendation, since job openings and
applications have different natural owners in practice. `update_stage`,
`add_note`, and `mark_ready_for_conversion` are deliberately their own
permission keys rather than folded into `.update` — same "narrow write
action" reasoning as `hr_generated_documents.submit`/`.approve`/
`.reject` — so a role can be trusted to move the pipeline forward or
leave notes without ever holding `.delete`. Grants: Tenant Admin/HR
Director/HR Manager hold every key on both resources; HR Officer holds
everything except `.delete` (per your explicit "safer" mapping);
Auditor holds `.view` only on both; Line Manager and Employee hold
nothing — there's no assigned-interviewer scoping model yet to base a
partial Line Manager grant on (unlike `leave.approve`'s direct-reports
scoping or Lifecycle's `LifecycleVisibilityService`), and shipping a
fake partial scope would be worse than shipping no access at all.

### Candidate data is personal data — handled at the same tier as everything else, not a new tier

Applicant name/email/phone/cover letter are gated uniformly behind
`job_applications.view`, the same single-tier model every other
resource in this app uses (there's no `employees.view_sensitive`-style
second gate here). This is a deliberate scope decision, documented as a
limitation below, not an oversight: introducing a second sensitivity
tier for recruitment data without a concrete requirement driving it
would be speculative complexity this checkpoint doesn't need.

### Stage transitions are guarded server-side, never inferred from the endpoint

`ApplicationStage::canTransitionTo()`/`allowedNextStates()` is the
single source of truth for every `PATCH .../stage` call — mirrors
`LifecycleProcessStatus`/`RecruitmentJobStatus`/`HrGeneratedDocumentStatus`'s
identical transition-guard shape. An illegal transition (e.g.
`applied` → `hired` directly) is rejected with a `422` before anything
is written; `hired`/`rejected`/`withdrawn` are terminal.

### Ready-for-conversion is a flag, not a conversion — and its own permission

`PATCH .../ready-for-conversion` never creates an `Employee` row; it
only toggles `recruitment_applications.ready_for_conversion`, gated by
its own `job_applications.mark_ready_for_conversion` permission (your
approved choice over reusing `.update_stage`) so the eventual real
conversion feature's permission boundary is independent from ordinary
pipeline management from day one. A `rejected`/`withdrawn` application
can never be marked ready — validated server-side
(`MarkApplicationReadyForConversionRequest`), not just hidden in the UI.

### Notes and cover letters are never logged verbatim

`recruitment_application_notes.note` and `recruitment_applications.cover_letter`
are real candidate-authored free text. `job_application_note.created`
and `job_application.updated` audit entries record that a note was
added or a cover letter changed, never the text itself — the same "no
full free-text content in audit metadata" rule already established for
HR document `rendered_content` (Checkpoint 34) and leave rejection
reasons (Checkpoint 14). Notes are internal-only this checkpoint
(`visibility: 'internal'`, never accepted from request input, no
candidate-facing view exists) — there is no candidate portal.

### Current limitations

- No dedupe/merge-by-email — every `POST /job-applications` creates a
  fresh `recruitment_applicants` row, even for a repeat applicant
  (documented in `architecture.md`); a real "existing applicant"
  lookup/merge flow is future work.
- No second sensitivity tier for candidate PII — applicant data is
  gated at the same `job_applications.view` level as everything else,
  not a separate `view_sensitive`-style gate.
- No assigned-interviewer scoping — Line Manager holds no recruitment
  permissions at all this checkpoint, rather than a partial,
  unenforceable scope.
- Candidate-to-employee conversion exists as of Checkpoint 40 (see
  below) but creates no `User` account, no role assignment, and starts
  no onboarding process automatically.
- No public candidate portal, job-board posting, CV parsing, AI
  screening, interview scheduling, offer approval/automation, email
  notifications, or bulk import.

### Future

- A public candidate portal and application status visibility for the
  candidate themselves.
- Interview scheduling, offer approval routing, and offer-letter
  automation (would reuse the existing HR Documents module once
  scoped).
- AI-assisted resume screening/parsing, and job-board publishing
  integrations.
- Applicant dedupe/merge-by-email, and a second sensitivity tier for
  candidate PII, if a real need is shown.

## Candidate-to-Employee Conversion Foundation (Checkpoint 40)

Adds `POST /api/v1/job-applications/{id}/convert-to-employee`. See
[`architecture.md`](architecture.md#candidate-to-employee-conversion-foundation-checkpoint-40)
for the schema/technical writeup; this section covers the security
design.

### Standard three-layer tenant isolation, applied to three related resources at once

The conversion route sits inside the same `auth` → `tenant.matches` →
`permission:{key}` stack every route here uses.
`JobApplicationController::convertToEmployee()` additionally calls
`ensureBelongsToCurrentTenant()` on the application (defense-in-depth
beyond `BelongsToTenant`'s global scope) before touching anything —
and since the application's `job`/`applicant` relations are only ever
created within the same tenant (Checkpoint 39's single-step create), a
same-tenant application guarantees a same-tenant job and applicant too.
A cross-tenant application ID 404s before any employee row is even
considered.

### Eligibility is a hard gate, checked twice, never trusted from the frontend

`stage === hired` **and** `ready_for_conversion === true` **and**
`converted_employee_id === null` — all three checked in
`ConvertApplicationToEmployeeRequest::withValidator()` (rejecting the
whole request with a `422` before the controller runs) and again in the
controller itself via `abort_unless()` (defense-in-depth, same
belt-and-braces pattern the rest of this app uses for every
precondition). None of the three is inferable or overridable from
request input — `stage`/`ready_for_conversion` are read from the
application's own persisted state, not the request body.

### One deliberately narrow permission, not a permission composition

`job_applications.convert_to_employee` is required, and only that —
not `employees.create` as well, per your explicit approved choice
(the checkpoint brief asked for "one deliberate permission," and every
role that gets it already holds `employees.create` in practice
anyway). Granted to Tenant Admin/HR Director/HR Manager; **not** HR
Officer by default, despite HR Officer already holding every other
recruitment write permission — converting to an employee is a
materially bigger, harder-to-reverse action than moving a pipeline
stage or adding a note. Not granted to Auditor, Line Manager, or
Employee.

### Field validation reuses StoreEmployeeRequest's exact rules — never a looser path

`ConvertApplicationToEmployeeRequest` duplicates
`StoreEmployeeRequest`'s `employee_number`/`work_email` per-tenant
uniqueness checks and the active-department/position/location
existence checks verbatim. Creating an `Employee` via conversion can
never be easier to abuse (e.g. a duplicate `employee_number`, or
assignment to an archived department) than creating one the normal
way.

### Server-controlled conversion fields — nothing accepted from the frontend

`converted_employee_id`, `converted_at`, `converted_by`, `tenant_id`,
`created_by`, and `updated_by` are never in
`ConvertApplicationToEmployeeRequest`'s validated fields at all — not
merely stripped, genuinely absent from the rule set, so submitting them
has zero effect regardless of value. `manager_employee_id` is likewise
never accepted here — assigning a manager remains the exclusive job of
`PATCH /employees/{id}/manager`, matching the same rule every other
employee-creation path in this app already follows.

### Idempotent — a second conversion attempt is rejected outright

`converted_employee_id !== null` on the application short-circuits any
further attempt with a `422`, both in the FormRequest and the
controller. There is no "re-convert" or "overwrite the previous
conversion" path.

### Transactional — no partial state on failure

`Employee::create()` and the application's `converted_employee_id`/
`converted_at`/`converted_by` update happen inside one
`DB::transaction()`. In practice, uniqueness failures are caught even
earlier by FormRequest validation (before the transaction opens), so no
genuinely partial write has been observed in testing — the transaction
exists as the guarantee for any failure mode validation doesn't already
catch.

### No automatic user account, role assignment, or onboarding

Conversion creates exactly one row: the `Employee`. No `User` account
is created or linked, no role is assigned, and no lifecycle process
starts. The UI links to the existing `/lifecycle/create` page
(pre-filled via query string) as a manual next step — Option A, your
approved choice, avoiding the need to decide *which* onboarding
type/tasks would be appropriate by default.

### Audit logging — two entries, never the candidate's free text

`job_application.converted_to_employee` (module `recruitment`) and
`employee.created_from_recruitment` (module `employees`) are both
written on success. Neither ever includes `cover_letter` or note text
— only IDs and the already-safe employee fields (`employee_number`,
names, `work_email`, `employment_type`, `department_id`/`position_id`/
`location_id`, `start_date`), consistent with every other audit action
in this app's "no full free-text content in metadata" rule.

### Current limitations

- No automatic `User` account creation, role assignment, or onboarding
  process start — all deliberately out of scope this checkpoint.
- No applicant dedupe/merge-by-email carried through to conversion —
  two independent applications from the same real person (Checkpoint
  39's existing limitation) could each be converted into two separate
  employee rows.
- No offer-letter automation tying the HR Documents module to the offer
  stage.
- `manager_employee_id` is never set at conversion time — must be
  assigned as a separate follow-up action via the existing Employee
  edit page.

### Future

- Optional automatic `User` account creation and role assignment at
  conversion time, once a real need and a safe default role mapping are
  scoped.
- An "start onboarding automatically" checkbox (Option B), if manual
  Option A proves insufficient in practice.
- Offer-letter automation reusing the HR Documents module.
- Bulk conversion, if a real need is shown.

## Recruitment-to-Onboarding Handoff Foundation (Checkpoint 41)

Adds `POST /api/v1/job-applications/{id}/start-onboarding`. See
[`architecture.md`](architecture.md#recruitment-to-onboarding-handoff-foundation-checkpoint-41)
for the schema/technical writeup; this section covers the security
design.

### No request body — nothing for a caller to forge

Unlike conversion (Checkpoint 40), which at least validates real
submitted fields via `ConvertApplicationToEmployeeRequest`, this
endpoint reads nothing from the request at all.
`employee_id`/`type`/`status` on the created `LifecycleProcess` are
derived entirely from the application's own persisted
`converted_employee_id` and hardcoded `onboarding`/`draft` values — any
body content sent is silently ignored, verified directly by
`test_forged_body_fields_are_ignored_on_start_onboarding`.

### Three preconditions, all server-side, none trusted from the frontend

`converted_employee_id !== null`, `onboarding_process_id === null`, and
no existing non-terminal (`draft`/`in_progress`) `LifecycleProcess` for
the converted employee — each checked via `abort_unless`/`abort_if`
before anything is written. The third check deliberately looks at the
*employee*, not just this application, since the same employee could in
principle be reachable through more than one converted application in
the future; a prior `completed`/`cancelled` process is correctly not
treated as "still active," reusing `LifecycleProcessStatus::isTerminal()`
unchanged from Checkpoint 33 rather than a new parallel rule.

### Permission: `lifecycle.create`, reused — and a real gap closed, not just documented

Starting onboarding is gated by the same `lifecycle.create` permission
Checkpoint 33 already uses for creating a lifecycle process directly —
a deliberate choice over inventing a recruitment-specific permission,
since this is the same action reached from a different entry point.
Auditing role grants for this checkpoint surfaced that **HR Director
held `job_applications.convert_to_employee` but no `lifecycle.*`
permission at all** — meaning it could convert a candidate to an
employee but had no way to start their onboarding, a real dead end in
the seeded role model, not a hypothetical one. `RoleSeeder` now grants
HR Director the same full `lifecycle.view/create/update/delete/
assign_task/complete_task` set HR Manager already holds. HR Officer,
Auditor, Line Manager, and Employee are unaffected — none held
`job_applications.convert_to_employee` either, so none had this gap.

### Standard tenant isolation, applied across two related resources

The route sits inside the usual `auth` → `tenant.matches` →
`permission:lifecycle.create` stack.
`JobApplicationController::startOnboarding()` additionally calls
`ensureBelongsToCurrentTenant()` on the application before touching
anything, then independently re-checks the resolved `convertedEmployee`
actually belongs to the same tenant (`404` otherwise) — defense in
depth beyond `BelongsToTenant`'s global scope, the same "verify the
parent-child relationship explicitly, don't just trust the FK resolved"
pattern established for nested resources since Checkpoint 19's document
tests.

### Transactional — no partial state on failure

Creating the `LifecycleProcess` row and setting the application's
`onboarding_process_id` happen inside one `DB::transaction()` — both
succeed together or neither does, identical to Checkpoint 40's
conversion transaction.

### Idempotent — a second attempt is rejected outright

`onboarding_process_id !== null` on the application short-circuits any
further attempt with a `422`. There is no "restart" or "replace the
existing onboarding process" path through this endpoint.

### Audit logging — two entries, one per resource touched

`job_application.onboarding_started` (module `recruitment`) and
`employee_lifecycle_process.created_from_recruitment` (module
`lifecycle`) are both written on success — the same "one entry per
resource touched" shape Checkpoint 40 established for conversion.
Neither includes any free-text content; only IDs and the employee's
already-safe `full_name`/`employee_number`.

### Current limitations

- Creates only the bare `LifecycleProcess` record — no `User` account,
  no role assignment, and no notifications to the new employee or
  their manager. Deliberately out of scope, unchanged from Checkpoint
  40's original deferred list. (Task templates — the other item
  originally listed here — were resolved in Checkpoint 42, immediately
  below.)
- The "no other active onboarding process" guard checks the converted
  employee's `LifecycleProcess` rows generally, not specifically ones
  created through this same recruitment path — correct behavior, but
  worth noting if a future checkpoint ever needs to distinguish
  "started from recruitment" from "started manually" beyond the
  metadata already recorded in the audit log.

### Future

- Notifications to the new employee or their manager that onboarding
  has started.
- Automatic `User` account creation and role assignment at this same
  handoff point — Checkpoint 40's original deferred scope, still
  unaddressed.

## Onboarding & Offboarding Task Templates Foundation (Checkpoint 42)

Adds `lifecycle_task_templates` and its full CRUD API
(`/api/v1/lifecycle-task-templates`). See
[`architecture.md`](architecture.md#onboarding--offboarding-task-templates-foundation-checkpoint-42)
for the schema/technical writeup; this section covers the security
design.

### Its own permission group, mirroring each role's existing lifecycle.* posture exactly

`lifecycle_task_templates.{view,create,update,delete}` is deliberately
separate from `lifecycle.*` — managing the template catalog (admin
configuration) is a different trust level from working the actual
processes/tasks it feeds, the same split this app already applies to
`document_categories.*` vs. `documents.*`. Rather than making a fresh
judgment call on who should hold it, each role's grant mirrors its
*existing* `lifecycle.*` posture exactly: Tenant Admin (blanket, as
always), HR Manager and HR Director both hold full `lifecycle.*`
already and get full template-manage rights too; HR Officer holds
`lifecycle.view/create/update` (no delete) and gets the identical
view/create/update-only template grant; Auditor holds `lifecycle.view`
only and gets template-view only. Employee and Line Manager hold
neither `lifecycle.create`/`update`/`delete` nor any
`lifecycle_task_templates.*` permission — they interact with tasks
already on a process, never the template catalog that seeds them.

### Standard two-layer tenant isolation, same as every other lookup catalog

`LifecycleTaskTemplateController` follows the identical
`BelongsToTenant` global scope + `ensureBelongsToCurrentTenant()`
defense-in-depth shape as `DepartmentController`/`PositionController`/
`LocationController` — a cross-tenant template ID 404s on show/update/
destroy, and `Rule::unique()` on create/update is scoped to
`(tenant_id, type)`, so a forged `tenant_id` in the request body can
never collide with (or overwrite) another tenant's template, and can
never make a new template visible cross-tenant either (the explicit
`tenant_id = app(Tenant::class)->id` assignment in `store()` ignores
whatever the request body claims).

### Template application never crosses a tenant or type boundary

`LifecycleTaskTemplateApplier::applyToProcess()` queries strictly by
the *process's own* `tenant_id` and `type` — there is no code path
that could apply tenant A's templates to a process created in tenant
B, or apply an offboarding template to an onboarding process (or vice
versa). Archived (soft-deleted) templates are excluded automatically,
since the query never adds `withTrashed()`.

### Generated tasks are copied, not linked — an archived template can never retroactively change anything

Once `LifecycleTaskTemplateApplier` copies a template's `title`/
`description`/computed `due_date` into a real `LifecycleTask` row, that
row has no foreign key or other reference back to the template it came
from. Editing or archiving a template afterward has zero effect on any
task already generated from it — the same non-retroactive guarantee
HR Document generation already provides for `rendered_content` vs. its
source template (Checkpoint 34).

### No request field lets a caller control who a generated task is assigned to, or its actual status

`LifecycleTaskTemplateApplier` hardcodes `assigned_to_user_id: null`
and `status: pending` on every task it creates — neither is read from
the template row (templates don't have either column at all) nor from
any request input, since process creation (`StoreLifecycleProcessRequest`)
and the onboarding handoff (Checkpoint 41) both take no per-task input
whatsoever. Assigning a generated task to someone remains the existing,
separately-permissioned `lifecycle.assign_task` action via
`LifecycleTaskController::update()`.

### Transactional — new for the direct-create endpoint

`LifecycleProcessController::store()` did not previously need a
transaction (a single-row `create()`); now that it also creates however
many template-derived tasks in the same request, both happen inside one
`DB::transaction()`, so a failure partway through (e.g. a future
constraint this app's own validation doesn't yet anticipate) can never
leave a process with only some of its expected starter tasks.
`JobApplicationController::startOnboarding()`'s existing transaction
(Checkpoint 41) simply gained the same applier call inside its already-
existing closure.

### Current limitations

- No default assignee per template, no bulk reorder/duplicate of
  templates (Checkpoint 45 reordered generated *tasks*, not the
  template catalog that generates them), and no traceability from a
  generated task back to the template that created it — all deliberate
  scope cuts, not oversights. ~~No notifications when a
  template-derived task's due date arrives~~ — closed in Checkpoint 45,
  for every task (template-derived or manual), not templates alone.
- The "no other active onboarding process" guard (Checkpoint 41) and
  this checkpoint's template application are independent checks; a
  future checkpoint that wants to skip re-applying certain templates on
  a *resubmitted* process (there is no such concept yet) would need new
  design work, not an extension of what exists today.

### Future

- A default-assignee-by-role option per template (e.g. "IT setup tasks
  always assign to whoever holds the IT Support role").
- ~~Due-date notifications/reminders for template-derived tasks.~~ Done
  in Checkpoint 45, for all tasks.
- A `source_template_id` trace column, if a future need arises to
  distinguish template-derived tasks from manually created ones beyond
  what the audit log already records.

## User Account Provisioning (Checkpoint 43)

Adds the first user-creation endpoint in this app,
`POST /api/v1/users`. See
[`architecture.md`](architecture.md#user-account-provisioning-checkpoint-43)
for the technical writeup; this section covers the security design.

### A reserved permission, finally wired up — not a new one invented

`users.create` was seeded back in Checkpoint 23 alongside `users.view`/
`users.deactivate`/`users.assign_role` as a natural CRUD verb, but no
route ever checked it until this checkpoint. Reusing it means the
permission catalog doesn't grow for this feature, and any tenant that
had (unusually) already granted `users.create` to a custom role gets
the new capability automatically — the same "permission was already
the right shape, just unused" situation `hr_document_templates.publish`
was in before Checkpoint 36 wired it up.

### One permission gates a compound action, on purpose

`POST /api/v1/users` requires only `users.create` — not additionally
`users.assign_role` (even though the endpoint assigns a role) or
`employees.link_user` (even though it can link an employee in the same
request). This mirrors the precedent `job_applications.convert_to_employee`
already set: a single, deliberately-chosen permission gates a
multi-effect action, rather than requiring the caller to separately
hold every permission for each individual effect. `User::assignRole()`
still independently re-checks platform-vs-tenant role scope as a
backstop (unreachable given `StoreUserRequest`'s own validation, kept
anyway per this app's layered-guard convention).

### Role and employee validated exactly like their existing single-purpose actions

`role_id` uses the identical tenant-and-scope-restricted `Rule::exists()`
`AssignUserRoleRequest` already uses (a tenant role, not a platform
role, belonging to the current tenant). `employee_id` (optional) is
checked against the same two preconditions `LinkEmployeeUserRequest`
already enforces for linking an *existing* user — not already linked,
not terminated — just evaluated against the `employee_id` request
input instead of a route-bound model, since this endpoint has no
employee route parameter.

### Transactional — creation, role assignment, and the optional link succeed or fail together

`UserController::store()` wraps `User::create()`, `assignRole()`, and
the conditional `Employee::update()` in one `DB::transaction()`. There
is no window where the account exists without a role, or where an
`employee_id` was accepted but the link silently didn't happen.

### Deliberately manual, never automatic — matching this app's existing linking philosophy

Per your explicit approved scope choice, this is a separate, standalone
action — never triggered by `JobApplicationController::convertToEmployee()`
(Checkpoint 40) or `::startOnboarding()` (Checkpoint 41). Both of those
endpoints' docblocks already stated "no user account... created
automatically" before this checkpoint existed, and that remains true.
This mirrors `EmployeeUserLinkController`'s own documented rule that
linking an existing user is always a deliberate action, never a side
effect — creating one now follows the identical philosophy.

### The password is never returned, logged, or recoverable through this app

`StoreUserRequest` requires `password` (`confirmed`, `Password::min(8)`)
— the caller sets the account's real initial password directly, since
no invite-email/password-reset flow exists (see "Current limitations").
`UserResource` already excluded `password`/`remember_token` before this
checkpoint (Checkpoint 23's own `#[Hidden(...)]` attribute on the
model). The `user.created` audit log's `new_values` records only
`name`/`email`/`role_id`/`employee_id` — never the password, matching
every other "never the sensitive input itself" audit log in this app
(cover letters, rejection reasons, leave reasons, document contents).

### `roles.view` granted to HR Manager for a real, functional reason

The Create User page's role picker calls `GET /api/v1/roles`, which
independently requires `roles.view` — a permission HR Manager never
held before this checkpoint. Granting it alongside `users.create` isn't
a separate trust decision; it's the same "a real gap found while
building the form" pattern already used for `employees.view`/
`document_categories.view` in Checkpoints 19/33. HR Manager still
cannot create, edit, or delete roles (no `roles.create/update/delete`).

### Current limitations

- No invite-email or password-reset flow — the caller sets the real
  password directly; there is no way for the new user to set their own
  password without the creator sharing it out of band.
- No "resend/reset credentials" action for an account created this way.
- No email verification is enforced (same pre-existing gap as every
  other user in this app — `email_verified_at` is set immediately,
  matching `UserSeeder`'s own convention, but nothing ever checks it).
- No bulk user import or CSV-based provisioning.
- HR Officer, Line Manager, Employee, and Auditor do not hold
  `users.create` — only Tenant Admin and HR Manager can create user
  accounts this checkpoint, the same trust boundary already applied to
  `employees.link_user`/`unlink_user`.

### Future

- A real invite-email/password-reset flow — the single biggest gap
  this checkpoint deliberately leaves open.
- A "resend credentials" or forced-password-change-on-first-login
  option once that flow exists.
- Bulk/CSV user import.

## Password Reset (Checkpoint 44)

Adds this app's first real forgot-password flow — `GET`/`POST
/forgot-password` and `GET`/`POST /reset-password` — and, in the
process, the first real email this app ever sends. See
[`architecture.md`](architecture.md#password-reset-checkpoint-44) for
the technical writeup; this section covers the security design.

### Never reveals whether an email exists, or which tenant it belongs to

`POST /forgot-password` always returns the identical redirect and
flashed message — `"If an account exists for that email, we've sent a
password reset link."` — regardless of whether the email matches a
real user, matches a user in a *different* tenant, or matches a
platform admin being requested from a tenant subdomain. This is the
same non-enumerating posture `LoginRequest` already established for bad
credentials (`"These credentials do not match our records."` either
way). `POST /reset-password` mirrors it: every rejection (invalid
token, expired token, no such user, wrong-tenant submission) surfaces
the identical generic message, `"This password reset link is invalid or
has expired."`

### The tenant boundary is enforced twice — once at send, once at reset

Both `ForgotPasswordRequest::sendResetLinkIfEligible()` and
`ResetPasswordRequest::reset()` independently re-implement the same
platform-admin-vs-tenant-vs-resolved-tenant check `LoginRequest::isAllowedToLoginHere()`
and `EnsureTenantMatchesAuthenticatedUser` already perform elsewhere —
duplicated, not shared (same reasoning already documented for
`StoreUserRequest`'s duplicated employee-state check in Checkpoint 43:
these three call sites validate against different sources — an
authenticated session, a login attempt, and here, an email address with
no session at all — not worth a premature shared helper).

- **At send time**: a request for `user@tenantA.example` arriving on
  tenant B's subdomain (or the base domain, or vice versa for a
  platform admin) never gets `Password::sendResetLink()` called at all
  — no token is even generated, let alone emailed.
- **At reset time**: even a genuinely valid token+email pair (the real
  secret proving the requester received the email) is still rejected if
  the `POST /reset-password` request arrives on the wrong tenant's
  subdomain. In the normal flow this never triggers — the emailed link
  is itself tenant-aware (see below) and always lands the user back on
  their own subdomain — but it's a real, independent check, not
  incidental.

### The reset link is tenant-aware, because nothing else would make it usable

`User` has no `BelongsToTenant` scope and no request context to infer a
host from at notification-build time, so Laravel's default
`ResetPassword` notification (which builds its URL via a plain
`route()` call) would never reliably point back to the *right*
`{subdomain}.{base_domain}`. `AppServiceProvider::boot()` registers
`ResetPassword::createUrlUsing()` to build the URL explicitly: a tenant
user's link always points to their own tenant's subdomain; a platform
admin's (`is_platform_admin` true, or no tenant at all) points to the
base domain. This runs at send time, inside the same request that
validated eligibility above — so a link is only ever built for a user
who actually passed the tenant-boundary check in the first place.

### Every attempt is audit-logged — sent or not, succeeded or not

`password_reset.requested` is written for every `POST /forgot-password`
call, whether or not a real link was sent (`target_user_id` is `null`
and `metadata.attempted_email` is recorded when the email doesn't
resolve to a real user, the same shape `login.failed` already uses).
`password_reset.completed` / `password_reset.failed` are written for
every `POST /reset-password` call. None of these ever record the
token or the new password — `AuditLogger`'s existing `password`/`token`
substring masking would catch it even if a future edit accidentally
tried.

### No queued email — a deliberate, documented choice, not an oversight

Nothing in this app implements `ShouldQueue` yet (see
`docs/deployment.md` "Queue/Cache/Session Readiness" — `QUEUE_CONNECTION`
is configured but unused in practice). The `ResetPassword` notification
sends synchronously, inside the same request that handles
`POST /forgot-password`. This is fine at foundation-stage traffic
levels and keeps this checkpoint from being the first to introduce a
queued job; revisit once a queue worker is actually part of this app's
deployment story.

### Current limitations

- No invite-email flow specifically — creating a new account
  (Checkpoint 43) still requires the admin to set a real initial
  password directly; this checkpoint didn't change that flow, only
  added a way for *any* existing account (including one just created)
  to reset its own password afterward.
- No rate limiting beyond Laravel's own built-in per-email token
  throttle (`config('auth.passwords.users.throttle')`, 60 seconds) — no
  additional IP-based throttling on `/forgot-password` itself.
- No "your password was changed" confirmation email after a successful
  reset — only the audit log records it.
- Password reset emails are sent synchronously (see above) — a slow
  mail provider in production would add latency directly to the
  `POST /forgot-password` request.
- `MAIL_MAILER=log` locally (see `.env.example`) means the actual reset
  link only ever appears in `storage/logs/laravel.log`, not a real
  inbox, until a real mail provider is configured.

### Future

- A real invite-email flow for Checkpoint 43's create action, reusing
  this checkpoint's tenant-aware URL-building approach.
- Queue the reset email once this app has a real queue worker story.
- A post-reset confirmation email.
- IP-based rate limiting on `/forgot-password`, on top of the existing
  per-email throttle.

## Lifecycle Task Ordering & Reminders (Checkpoint 45)

Adds manual task ordering (a `sort_order` column plus a bulk-reorder
endpoint and drag-and-drop UI) and this app's first scheduled task — a
daily email digest of overdue/due-soon lifecycle tasks. See
[`architecture.md`](architecture.md#lifecycle-task-ordering--reminders-checkpoint-45)
for the technical writeup; this section covers the security design.

### Reordering is gated by `lifecycle.update`, not a new permission

`POST /lifecycle-processes/{id}/tasks/reorder` requires the identical
permission editing the process itself already requires. No narrower
key was invented — every role holding `lifecycle.update` (Tenant Admin,
HR Manager, HR Director, HR Officer where granted) is already fully
trusted to edit any task on the process, and reordering changes nothing
about a task's content, assignment, or status. This differs from
`hr_generated_documents.submit`/`.approve`/`.reject`, which deliberately
*do* have their own keys — those exist specifically so HR Officer can
submit without ever self-approving; no comparable "should be able to
reorder but not otherwise edit" role exists in this app's approved
permission mapping, so no split was introduced here.

### `ReorderLifecycleTasksRequest` requires the complete task set — never a partial move

The request validates that `task_ids` is exactly the process's current
set of task IDs (same count, no foreign IDs from another process, no
duplicates, no omissions) before any write happens. A caller cannot
smuggle in a task ID belonging to a different process (which would
otherwise let a `lifecycle.update` holder silently pull a task out of
one process's ordering into another's, since the reorder loop only
updates `sort_order` by ID, not by relationship) — the "exact set"
validation closes that off entirely, not just a naive "does this ID
exist somewhere" check.

### Same tenant-isolation and terminal-process rules as every other task mutation

`LifecycleTaskController::reorder()` calls the same
`ensureProcessBelongsToCurrentTenant()` 404-not-403 check every other
action on this controller uses, and `ReorderLifecycleTasksRequest`
rejects reordering on a completed/cancelled process, the same rule
`StoreLifecycleTaskRequest` already applies to adding a task in the
first place.

### The digest email is the first scheduled, system-triggered action in this app — and the first place tenant scoping is applied manually, outside a request

Every prior tenant-scoped query in this app runs inside an HTTP request,
where `ResolveTenant` middleware has already bound a `Tenant` into the
container before any controller code runs. `SendLifecycleTaskDigest`
has no request — it runs from `php artisan` on a schedule — so it binds
each active tenant into the container itself
(`app()->instance(Tenant::class, $tenant)`), one at a time, immediately
before querying that tenant's tasks. This is the exact same mechanism
`ResolveTenant` uses, applied manually because there is no middleware
pipeline here to do it automatically. Getting this wrong (e.g.
forgetting to bind, or binding once outside the per-tenant loop) would
mean `LifecycleTask::query()`'s `BelongsToTenant` global scope either
doesn't filter at all (leaking every tenant's tasks into every digest)
or filters every tenant's query against whichever tenant was bound
first — both failure modes are structurally impossible here since the
binding happens fresh inside `digestForTenant()` for every tenant in
the loop, never once before it.

### No new permission is checked at all — this is not an HTTP-reachable action

`lifecycle:send-task-digest` has no route, no controller, no
`permission:` middleware — it's only reachable via the CLI or the
scheduler, the same posture `AuditTenantRouteScoping` already has. There
is no user-triggered "send digest now" button anywhere in this app;
adding one would need its own permission decision, deliberately not
made this checkpoint.

### An assignee's active status is re-checked at send time, not just at assignment time

`StoreLifecycleTaskRequest`/`UpdateLifecycleTaskRequest` already require
`assigned_to_user_id` to reference an active, non-platform-admin user in
the same tenant — but only at the moment of assignment. An assignee can
be deactivated afterward without their existing task assignments being
touched. `SendLifecycleTaskDigest` re-checks `$assignee->isActive()`
before sending, skipping a deactivated assignee's tasks entirely rather
than emailing an account that should no longer be receiving anything.

### Deliberately not queued — a scoped decision, not an oversight

See `docs/architecture.md`'s "Deliberately not queued" note and
`docs/deployment.md` §6: queuing this notification would introduce a
*second* new always-on infrastructure dependency (a persistent
`queue:work`/supervisor process) in the same checkpoint that introduces
the *first* (the scheduler's cron entry). Sending synchronously from
within the already-scheduled command keeps this checkpoint to one new
infrastructure dependency, not two.

### Current limitations

- No per-tenant timezone for the digest's send time — every tenant's
  digest fires at the same 07:00 server-time moment regardless of where
  they operate.
- No digest suppression/snooze — an assignee with an overdue task gets
  the identical email every single day until they complete or skip it.
- No "send digest now" on-demand action — the digest is only reachable
  via the scheduler/CLI, never an HTTP endpoint, so there is no
  permission decision to make here yet either.
- Task **ordering** is display order only, not dependencies/blocking —
  completing or skipping a task never checks any other task's state,
  regardless of `sort_order`.
- The reorder endpoint has no rate limiting beyond whatever applies to
  the API generally — a caller with `lifecycle.update` can call it
  arbitrarily often.

### Future

- Per-tenant timezone support for the digest send time.
- Digest suppression/snooze, or an in-app notification center as an
  alternative to email.
- Queue the digest notification once a real queue worker exists in this
  app's deployment story (see `docs/deployment.md` §6).
- True task dependencies (task B blocked until task A completes), if a
  real need for that emerges — explicitly out of scope for this
  checkpoint, which only added display ordering.

## Local Demo Credentials

**Local development only — these are not real secrets and only work against your own local database.** Password comes from `SEED_USER_PASSWORD` in `.env` (not committed; `.env.example` has an empty placeholder).

| Email | Role | Permission highlights |
|---|---|---|
| `super.admin@peopleos.test` | Platform Super Admin (base domain only) | All 6 `platform.*` permissions |
| `admin@uesl.peopleos.test` | UESL Tenant Admin | All tenant-level permissions (37) |
| `admin@airpeace.peopleos.test` | Air Peace Tenant Admin | All tenant-level permissions (37) |
| `admin@ibom.peopleos.test` | Ibom Air Tenant Admin | All tenant-level permissions (37) |
| `hr.manager@uesl.peopleos.test` | UESL HR Manager | Employee/document/leave/announcement management, not roles/tenant settings |
| `hr.officer@uesl.peopleos.test` | UESL HR Officer (Checkpoint 26) | Leave approval, policy authoring, `tenant.settings.view` — no user/role management |
| `line.manager@uesl.peopleos.test` | UESL Line Manager (Checkpoint 26) | Direct-report leave approval only — no tenant-wide visibility |
| `auditor@uesl.peopleos.test` | UESL Auditor (Checkpoint 26) | `audit.view` + tenant-wide read access, no admin writes |
| `employee@uesl.peopleos.test` | UESL Employee | Self-service basics, plus a direct-grant example (`documents.download`) |

Every demo tenant gets the full 20-role catalog seeded (see `security.md`
→ RBAC), but only Tenant Admin / HR Manager / HR Officer / Line Manager /
Employee / Auditor have real permission sets attached (all on `uesl`;
`airpeace`/`ibom` still only have their Tenant Admin) — the remaining
roles per tenant exist as empty placeholders for future modules. See
`docs/demo-guide.md` for the full seeded-employee/leave/document/policy
data these six `uesl` logins see (Checkpoint 26's `DemoDataSeeder`).

## Known Limitations / Follow-up

- No email verification enforcement on login (column exists, not yet checked).
- Password reset exists as of Checkpoint 44 (the `password_reset_tokens` table, previously unused, now backs a real forgot-password flow) — see [Password Reset](#password-reset-checkpoint-44) below. Still no invite-email, MFA, or SSO.
- This app's first scheduled task exists as of Checkpoint 45 (`lifecycle:send-task-digest`, registered via `bootstrap/app.php`'s `->withSchedule()`) — see [Lifecycle Task Ordering & Reminders](#lifecycle-task-ordering--reminders-checkpoint-45) below. Production deployments must now add a `php artisan schedule:run` cron entry, which was never required before this checkpoint (see `docs/deployment.md` §6). Still no queued jobs anywhere in the app.
- `DatabaseSeeder` uses `WithoutModelEvents`, which disables the `saving`/`creating` guards (on `User` and `Role`) during seeding. `UserSeeder`/`RoleSeeder` set `tenant_id`/`is_platform_admin`/`is_platform_role` explicitly on every row regardless, so this doesn't cause incorrect data — but it does mean a same-row consistency mistake in seed data would surface as a raw Postgres constraint error rather than the cleaner app-level exception. (The RBAC *assignment* guards — `assignRole()`, `givePermissionTo()`, `grantPermission()` — and audit logging calls are unaffected by this, since they're plain method logic, not Eloquent events.)
- See "Current limitations" under Audit Logging above for the audit-specific gaps (no read endpoint, `givePermissionTo()`/tenant CRUD not logged yet).
- No permission caching — `hasPermission()` hits the database on every call (two queries: role-permission lookup, direct-grant lookup). Fine for foundation-stage traffic; revisit if it becomes a hot path.
- 17 of 20 seeded tenant roles per tenant have no permissions attached yet (by design — placeholders for modules that don't exist yet).
- `employee_number` is manually provided, not auto-generated — no numbering-scheme feature exists yet.
- No salary, bank details, medical information, disciplinary records, or documents on employees yet — deliberately deferred to separate, more sensitive future checkpoints.
- `/api/v1` routes use the same session-based `web` auth as the rest of the app (no Sanctum/token guard yet) — see `docs/api.md` for the full future plan and what a token layer must support before it's added.
- `tenant.matches` is applied per-route, not globally — every new authenticated tenant-scoped route must remember to include it (alongside `auth`), the same way `permission:` is remembered per-route. Nothing currently enforces this at a higher level (e.g. a lint rule or test asserting every `web`-registered route under an authenticated prefix has it).
- No test exists proving `tenant.matches` behavior for a *platform admin who becomes a tenant user* or vice versa (role/type changes mid-session) — an edge case not currently possible via any existing code path (nothing changes `is_platform_admin` after creation), but worth a test if that ever becomes possible.
- No auto-reassignment when a policy is republished — employees already assigned to a superseded version keep their (now-stale) pending acknowledgement, which is correctly rejected if they try to confirm it, but nothing proactively creates a new pending row against the new version.
- No policy campaign automation, email reminders, escalations, or department-wide auto-assignment — explicitly out of scope this checkpoint.
- No acknowledgement export/report endpoint — `policies.export_acknowledgements` permission is seeded but unused.
- `employee_document_id` on `policy_versions` requires an existing employee-owned document — see "Policy Management" above for the schema mismatch this carries.
- **No self-linking / invitation-token flow, no employee profile self-update, no manager-approval linking workflow** — see [User ↔ Employee Linking](#user--employee-linking) above for the full list of what Checkpoint 11 deliberately left out.
- No Payroll, Performance, or Onboarding modules yet.
- **Leave balances exist (Checkpoint 15) but have no accrual engine, no carry-forward automation, no half-day leave, no public holiday calendar, no manager team-balance view** — see [Leave Balances Foundation](#leave-balances-foundation) above for the full list.
- **Leave Management still has no notifications or calendar integration** — see [Leave Management](#leave-management) above.
- **Line Manager can now approve/reject leave, but direct reports only** (Checkpoint 14) — indirect (skip-level) approval is a deliberate future policy decision, not built. See [Manager-Hierarchy-Scoped Leave Approval](#manager-hierarchy-scoped-leave-approval) above.
- **No org chart, manager self-service dashboard, or performance/probation review usage of the manager hierarchy** — see [Manager Hierarchy](#manager-hierarchy) above for the full list.
- **Employee Records, Leave Management, (employee-scoped) Document Repository, Policy Management, the Dashboard, Settings, Users & Access, Audit Log Viewing, and Document Categories/Leave Types admin all have real UIs now (Checkpoints 17/18/19/20/21/22/23/24/25); the top-level `/documents` route is still a permission-gated placeholder (no tenant-wide document centre yet — see [Document Repository UI](#document-repository-ui) above)** — see [Employee Records UI](#employee-records-ui), [Leave Management UI](#leave-management-ui), [Document Repository UI](#document-repository-ui), [Policy Management UI](#policy-management-ui), [Dashboard Foundation](#dashboard-foundation), [Settings Foundation](#settings-foundation), [Users & Access Management UI](#users--access-management-ui), [Audit Log Viewing UI](#audit-log-viewing-ui), and [Document Categories & Leave Types Admin UI](#document-categories--leave-types-admin-ui) above.
- **Leave Management UI has no balance/leave-type admin UI, calendar view, or notification integration** — see [Leave Management UI](#leave-management-ui) above.
- **Document Repository UI has no tenant-wide document centre, approval workflow UI, eSignature, document generation, or file preview** — see [Document Repository UI](#document-repository-ui) above.
- **Policy Management UI has no campaign automation, reminders/escalations, dashboard/compliance reporting, template library, bulk/department-wide assignment, or admin-recorded-on-behalf-of acknowledgement UI** — see [Policy Management UI](#policy-management-ui) above.
- **Dashboard has no charts, tenant-wide document cards, platform-level dashboard, or notifications** — see [Dashboard Foundation](#dashboard-foundation) above.
- **Settings has no integrations or billing/subscription management yet — only the tenant name is editable on the Company Profile card** (Document Category and Leave Type admin UIs were added in Checkpoint 25, and their Settings-hub cards no longer say "Coming later" as of Checkpoint 26) — see [Settings Foundation](#settings-foundation) above.
- **Users & Access has no invitation flow, password reset/MFA/SSO UI, direct/temporary permission grants, or access review workflow — role/status management stays Tenant-Admin-only** — see [Users & Access Management UI](#users--access-management-ui) above. Custom-role RBAC management (create/edit/permission assignment) was added in Checkpoint 28 — see below.
- **Audit Log Viewing UI has no export, SIEM integration, alerting, anomaly detection, advanced search, saved filters, platform-wide dashboard, or retention controls** — see [Audit Log Viewing UI](#audit-log-viewing-ui) above.
- **Document Categories & Leave Types admin UI has no bulk import/export, department/location/job-title admin, payroll configuration, or configuration-change notifications** — see [Document Categories & Leave Types Admin UI](#document-categories--leave-types-admin-ui) above.
- **No JS/TS unit test runner configured** — frontend verification relies on `tsc --noEmit`, `vite build`, and backend feature tests asserting Inertia response shape/shared-prop safety.
- **Demo data (Checkpoint 26) only covers the `uesl` tenant** — `airpeace`/`ibom` still only have their Tenant Admin login and no employees/leave/documents/policies, by design (avoiding excessive seeded tenants).
- **No invitation flow, password reset UI, MFA, or SSO** for the three new demo logins or any other user — unchanged from Checkpoint 23's scope, see [Users & Access Management UI](#users--access-management-ui) above. (RBAC role/permission editing for *custom* roles was added in Checkpoint 28 — built-in/system roles remain view-only; see [RBAC Role & Permission Management UI](#rbac-role--permission-management-ui-checkpoint-28) below.)
- **The build-size advisory from Checkpoint 25 is resolved** (Checkpoint 26) via lazy per-page resolution in `app.tsx` — see [Demo Readiness & UI Polish](#demo-readiness--ui-polish-checkpoint-26) above and `docs/architecture.md` for detail. No further bundle work is planned unless a future module meaningfully grows the app again.
- **`TrustProxies` is not configured** (Checkpoint 27) — required before deploying behind any reverse proxy/load balancer that terminates TLS; see [Deployment & Production Hardening](#deployment--production-hardening-checkpoint-27) above and `docs/production-readiness.md`.
- **CI now runs the verification suite automatically** (Checkpoint 29, confirmed green on the real GitHub Actions runner in Checkpoint 30) — a single lightweight GitHub Actions job runs the TypeScript check, frontend build, Postgres-backed migrations + tenant-route audit, backend test suite, and Pint on every push/PR to `main`/`master`. See `docs/quality-gate.md` for the full job breakdown.
- **No automated backup/restore tooling** — `docs/deployment.md` documents the practice, not an automated mechanism, since no real hosting environment exists yet to automate against.
- **No role deletion** (Checkpoint 28) — for any role, system or custom; a deliberate scope decision, not an oversight. See [RBAC Role & Permission Management UI](#rbac-role--permission-management-ui-checkpoint-28) above.
- **System/built-in roles cannot have their permissions edited at all** (Checkpoint 28) — the "safer MVP" lockdown chosen over a runtime "would this break the only admin path" check. See [RBAC Role & Permission Management UI](#rbac-role--permission-management-ui-checkpoint-28) above.
- **No role import/export, access review workflow, approval workflow for permission changes, segregation-of-duties engine, or permission risk scoring** — see [RBAC Role & Permission Management UI](#rbac-role--permission-management-ui-checkpoint-28) above.
- **Departments/Positions/Locations (Checkpoint 32) have no hierarchy, no usage-count guard before archiving, and no bulk import/export** — see [Employee Lifecycle Foundation](#employee-lifecycle-foundation-checkpoint-32) above.
- **Employment Type remains a fixed enum, not a tenant-configurable lookup table** (Checkpoint 32, deliberate scope decision) — see [Employee Lifecycle Foundation](#employee-lifecycle-foundation-checkpoint-32) above.
- **Onboarding & Offboarding (Checkpoint 33) has task templates as of Checkpoint 42, but still no task dependencies/ordering, approval routing, notifications, IT/asset provisioning integration, document generation, e-signature, or performance/probation review integration; Line Manager visibility is direct-reports only, not the full reporting tree** — see [Onboarding & Offboarding Foundation](#onboarding--offboarding-foundation-checkpoint-33) and [Onboarding & Offboarding Task Templates Foundation](#onboarding--offboarding-task-templates-foundation-checkpoint-42) above.
- **HR Documents & Letter Generation (Checkpoint 34) has no DOCX file, e-signature, automated sending, or bulk/employee-self-service generation — PDF export was added in Checkpoint 35 (generate-on-demand, never stored), template version history in Checkpoint 36 (no diff/compare UI, no publish-approval workflow), a single-approver approval workflow in Checkpoint 37 (no multi-level/routing approval, no notifications), and 8 seeded starter templates plus template duplication in Checkpoint 38 (tenant-specific only, no global/shared library, no AI generation)** — see [HR Documents & Letter Generation Foundation](#hr-documents--letter-generation-foundation-checkpoint-34), [PDF Export Dependency Review & Prototype](#pdf-export-dependency-review--prototype-checkpoint-35), [HR Document Template Versioning Foundation](#hr-document-template-versioning-foundation-checkpoint-36), [HR Document Approval Workflow Foundation](#hr-document-approval-workflow-foundation-checkpoint-37), and [HR Document Template Library & Starter Templates](#hr-document-template-library--starter-templates-checkpoint-38) above.
- **Recruitment & Applicant Tracking (Checkpoint 39) has no public candidate portal, no CV parsing/AI screening, no interview scheduling, no offer approval/automation, no email notifications, no bulk import, and no applicant dedupe/merge-by-email — Line Manager and Employee hold no recruitment permissions at all this checkpoint. Candidate-to-employee conversion was added in Checkpoint 40, gated by its own job_applications.convert_to_employee permission (not granted to HR Officer by default), but creates no User account or role assignment automatically. A real, trackable onboarding handoff was added in Checkpoint 41 (`start-onboarding`, gated by `lifecycle.create`) and now pre-populates default tasks from the template catalog (Checkpoint 42) — but still creates no User account, no role assignment, and no notifications** — see [Recruitment & Applicant Tracking Foundation](#recruitment--applicant-tracking-foundation-checkpoint-39), [Candidate-to-Employee Conversion Foundation](#candidate-to-employee-conversion-foundation-checkpoint-40), [Recruitment-to-Onboarding Handoff Foundation](#recruitment-to-onboarding-handoff-foundation-checkpoint-41), and [Onboarding & Offboarding Task Templates Foundation](#onboarding--offboarding-task-templates-foundation-checkpoint-42) above.
