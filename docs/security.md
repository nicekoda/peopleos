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
