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

**Decision: `POST /policies/{policy}/acknowledge` is admin/HR-recorded
only this checkpoint.** It requires an explicit `employee_id` in the
request body — there's no session-derived alternative. Every
acknowledgement created this checkpoint has
`acknowledgement_method = admin_recorded` (never `web`, which is reserved
for a future genuine self-service flow once real user-to-employee linking
exists).

**Consequence for the role mapping — a deliberate deviation from the
spec's suggestion, documented as instructed:** the suggested mapping gave
Employee `policies.acknowledge`. I did **not** grant it. Here's why: if a
rank-and-file Employee-role user could call `/acknowledge` with an
arbitrary `employee_id`, they could record an acknowledgement on behalf
of *any* employee in the tenant (already enumerable via the existing
`employees.view` permission most Employee-role users also hold) — not
just themselves. That's exactly the "insecure shortcut" instruction I was
told not to take. `policies.acknowledge` stays with HR-trusted roles
(Tenant Admin, HR Manager) until real self-service — requiring actual
identity verification — exists in a future checkpoint.

### Role mapping (as seeded in `RoleSeeder`)

| Role | Permissions |
|---|---|
| Tenant Admin | All 9 (automatic — granted every current tenant permission dynamically at seed time) |
| HR Manager | view, create, update, publish, assign, acknowledge, view_acknowledgements — **not** archive/export (per the spec's own suggested carve-out) |
| HR Officer | view, create, update, assign, view_acknowledgements — matches the spec exactly. First real permission grant for this role (a placeholder since Checkpoint 4) |
| Employee | **view only** — see the deviation above |
| Auditor | view, view_acknowledgements — matches the spec exactly. Also this role's first real grant |

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
- No genuine self-service policy acknowledgement — see "Policy Management" above. Requires real user-to-employee linking, not built yet.
- No auto-reassignment when a policy is republished — employees already assigned to a superseded version keep their (now-stale) pending acknowledgement, which is correctly rejected if they try to confirm it, but nothing proactively creates a new pending row against the new version.
- No policy campaign automation, email reminders, escalations, or department-wide auto-assignment — explicitly out of scope this checkpoint.
- No acknowledgement export/report endpoint — `policies.export_acknowledgements` permission is seeded but unused.
- `employee_document_id` on `policy_versions` requires an existing employee-owned document — see "Policy Management" above for the schema mismatch this carries.
