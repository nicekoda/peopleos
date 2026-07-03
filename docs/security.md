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

### Permission mapping (as seeded in `RoleSeeder`)

| Role | Permissions |
|---|---|
| Tenant Admin | All (automatic — every current tenant permission) |
| HR Manager | All leave permissions, per your explicit suggested mapping — `leave_types.*` + `leave.view`/`view_all`/`request`/`approve`/`reject`/`cancel` (includes `request`/`cancel` so an HR Manager who is also a linked employee can manage their own leave) |
| HR Officer | `leave_types.view`, `leave.view`, `leave.view_all`, `leave.approve`, `leave.reject` |
| Employee | `leave.view`, `leave.request`, `leave.cancel` — **no** `leave.view_all` |
| Auditor | `leave.view`, `leave.view_all` |
| Line Manager | **None** — see below |

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

### Why Line Manager gets no leave permissions this checkpoint

Your suggested mapping lists `leave.approve`/`leave.reject` for Line
Manager "if manager approval is supported." It isn't, this checkpoint —
`Employee.manager_employee_id` exists (Checkpoint 6) but nothing
validates "is this approver actually this employee's manager."
`LeaveRequestController::approve()`/`reject()` have no hierarchy scoping
at all: any holder of `leave.approve`/`leave.reject` can act on *any*
pending request in their tenant. Granting these to Line Manager under
that condition would let any Line Manager approve any employee's leave
company-wide — not scoped to their own reports, which is presumably the
entire point of a "Line Manager" role existing separately from "HR
Manager." This is the same category of decision as Checkpoint 10's
Employee/`policies.acknowledge` withholding: a suggested grant that
would create an unscoped blast radius without the feature that would
make it safe. Line Manager stays an empty placeholder (like 15 other
roles already are) until manager-hierarchy-scoped approval is built.

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
- **No manager-hierarchy-scoped approval** — see "Why Line Manager gets
  no leave permissions" above. `approve()`/`reject()` are tenant-wide
  for any `leave.approve`/`leave.reject` holder, not scoped to direct
  reports.
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
- Manager-hierarchy-scoped approval (`Employee.manager_employee_id`
  already exists; needs an actual "is this approver this employee's
  manager" check before Line Manager can safely receive
  `leave.approve`/`leave.reject`).
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

## Local Demo Credentials

**Local development only — these are not real secrets and only work against your own local database.** Password comes from `SEED_USER_PASSWORD` in `.env` (not committed; `.env.example` has an empty placeholder).

| Email | Role | Permission highlights |
|---|---|---|
| `super.admin@peopleos.test` | Platform Super Admin (base domain only) | All 6 `platform.*` permissions |
| `admin@uesl.peopleos.test` | UESL Tenant Admin | All tenant-level permissions (37) |
| `admin@airpeace.peopleos.test` | Air Peace Tenant Admin | All tenant-level permissions (37) |
| `admin@ibom.peopleos.test` | Ibom Air Tenant Admin | All tenant-level permissions (37) |
| `hr.manager@uesl.peopleos.test` | UESL HR Manager | Employee/document/leave/announcement management, not roles/tenant settings |
| `employee@uesl.peopleos.test` | UESL Employee | Self-service basics, plus a direct-grant example (`documents.download`) |

Every demo tenant gets the full 20-role catalog seeded (see `security.md`
→ RBAC), but only Tenant Admin / HR Manager / Employee have real
permission sets attached for `airpeace`/`ibom`/`uesl` respectively — the
other 17 roles per tenant exist as empty placeholders for future modules.

## Known Limitations / Follow-up

- No email verification enforcement on login (column exists, not yet checked).
- No password reset flow yet (table exists from Laravel's default scaffold, unused).
- `DatabaseSeeder` uses `WithoutModelEvents`, which disables the `saving`/`creating` guards (on `User` and `Role`) during seeding. `UserSeeder`/`RoleSeeder` set `tenant_id`/`is_platform_admin`/`is_platform_role` explicitly on every row regardless, so this doesn't cause incorrect data — but it does mean a same-row consistency mistake in seed data would surface as a raw Postgres constraint error rather than the cleaner app-level exception. (The RBAC *assignment* guards — `assignRole()`, `givePermissionTo()`, `grantPermission()` — and audit logging calls are unaffected by this, since they're plain method logic, not Eloquent events.)
- See "Current limitations" under Audit Logging above for the audit-specific gaps (no read endpoint, `givePermissionTo()`/tenant CRUD not logged yet).
- No permission caching — `hasPermission()` hits the database on every call (two queries: role-permission lookup, direct-grant lookup). Fine for foundation-stage traffic; revisit if it becomes a hot path.
- 17 of 20 seeded tenant roles per tenant have no permissions attached yet (by design — placeholders for modules that don't exist yet).
- No `departments`/`locations`/`positions` CRUD endpoints — see Employee Records above.
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
- **Leave Management has no balances/accrual, no manager-hierarchy-scoped approval, no notifications, no calendar integration** — see [Leave Management](#leave-management) above for the full list.
- **Line Manager still cannot approve/reject leave** — the hierarchy foundation now exists (Checkpoint 13), but `LeaveRequestController` hasn't been updated to use it yet. See [Manager Hierarchy](#manager-hierarchy) above.
- **No org chart, manager self-service dashboard, or performance/probation review usage of the manager hierarchy** — see [Manager Hierarchy](#manager-hierarchy) above for the full list.
