# Database

PostgreSQL. Migrations only — no manual schema changes.

## Conventions

- **Primary keys**: ULIDs (`$table->ulid('id')->primary()`) for `tenants`.
  `users`, `roles`, `permissions`, and their pivot tables deliberately use
  Laravel's default bigint auto-increment (a Checkpoint 4 decision — not
  changed to ULID even though `users` is now central to the app).
  **Internal database IDs may remain bigint.** The rule that matters is
  about what's *exposed*, not the storage type: public-facing links,
  invitation links, external portal links, document links, and any other
  sensitive reference visible outside an authenticated session must never
  expose a raw internal ID. Future modules that need a public-facing
  identifier must use a secure token, a ULID/UUID public ID (separate
  column from the bigint PK if needed), a signed URL, or a configured
  reference code — not `/employees/482`.
- **Tenant-owned tables** must include `tenant_id` (ULID, references
  `tenants.id`) and use the `BelongsToTenant` trait — see
  [`architecture.md`](architecture.md).
- **Soft deletes** on tables where records should be recoverable /
  audit-relevant rather than hard-deleted (e.g. `tenants`).
- **`created_by` / `updated_by`**: not yet added anywhere, including
  `users` itself — attributing a user's own creation needs an actor
  (creating admin or self-registration flow) that doesn't exist yet.
  Revisit once an admin-creates-user flow is built.

## Tables

### `tenants`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `name` | string | Display name |
| `subdomain` | string(63), unique | DNS label max length; used for tenant resolution |
| `status` | string | `active` \| `suspended` \| `inactive` (see `Tenant::STATUS_*`) |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable | Soft delete |

Seeded locally via `TenantSeeder` with three demo tenants (`uesl`,
`airpeace`, `ibom`) matching the local hosts-file subdomains — placeholder
names, rename directly in the table until tenant management tooling
exists.

### `users`

Laravel's default table (`id` bigint, `name`, `email` unique, `password`,
`email_verified_at`, `remember_token`, timestamps), extended with:

| Column | Type | Notes |
|---|---|---|
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | Null only for platform admins |
| `status` | string, default `active` | `active` \| `inactive` \| `suspended` (`User::STATUS_*`) |
| `is_platform_admin` | boolean, default `false` | See `docs/security.md` |
| `last_login_at` | timestamp, nullable | Set on successful login |
| `last_login_ip` | string(45), nullable | Set on successful login |
| `deleted_at` | timestamp, nullable | Soft delete |

Postgres `CHECK` constraint `users_platform_admin_tenant_consistency`
enforces `is_platform_admin` and `tenant_id` are always consistent (skipped
on SQLite; see `docs/security.md` for the app-layer equivalent).

See [`security.md`](security.md) for the full login/tenant-boundary design
and local demo credentials.

### `permissions`

A global catalog — not tenant-scoped itself. What's tenant-scoped is the
*assignment* of a permission to a role or user.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `key` | string, unique | e.g. `employees.view` — see naming convention in `security.md` |
| `category` | string | Derived grouping, e.g. `employees`, `platform` |
| `is_platform_permission` | boolean, default `false` | Platform-level vs. tenant-level |
| `description` | text, nullable | |

### `roles`

Each tenant owns its **own** copy of every tenant role — roles are not
shared templates. See `security.md` for why.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | Null only for platform roles |
| `is_platform_role` | boolean, default `false` | |
| `name` | string | |
| `slug` | string | Unique per tenant (`tenant_id`,`slug`); platform roles unique among themselves via a partial index (`WHERE tenant_id IS NULL`) |
| `description` | text, nullable | |
| `deleted_at` | timestamp, nullable | Soft delete |

Postgres `CHECK` constraint `roles_platform_tenant_consistency` mirrors the
same rule as `users` (skipped on SQLite; app-layer guard covers it there).

### `role_permission`, `user_role` (pivots)

Standard many-to-many pivots, each with a unique composite constraint to
prevent duplicate assignment. `user_role` additionally stores a
denormalized `tenant_id` (copied from the role at assignment time) so
tenant-scoped queries don't need a join through `roles`.

### `user_permissions`

Direct permission grants, outside role assignment.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | |
| `user_id` | bigint, FK → `users.id` `CASCADE` | |
| `permission_id` | bigint, FK → `permissions.id` `CASCADE` | |
| `granted_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `reason` | text, nullable | |
| `created_at` | timestamp | No `updated_at` — see `security.md` |

Unique (`user_id`,`permission_id`) — a user has at most one direct grant
per permission. Revocation is a hard delete of the row, not a soft delete
or status flag.

### `audit_logs`

Append-only — no `updated_at`, no `deleted_at`. Enforced at the model
layer too (`AuditLog::save()`/`delete()` throw on any update/delete
attempt), not just by the absence of an edit/delete UI. See
[`security.md`](security.md#audit-logging) for what's currently logged
and the masking rules.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | Null for platform-level events |
| `actor_user_id` | bigint, nullable, FK → `users.id` `SET NULL` | Null for system/seeder-driven events |
| `actor_type` | string, nullable | `user` \| `system`, inferred from `actor_user_id` if not given |
| `action` | string, required | e.g. `login.success`, `role.assigned` |
| `module` | string, required | e.g. `auth`, `rbac` |
| `auditable_type` / `auditable_id` | string, nullable | Polymorphic reference — `auditable_id` is a string (not a real FK) since referenced models have mixed PK types (bigint today, ULID for future tenant-owned models) |
| `target_user_id` | bigint, nullable, FK → `users.id` `SET NULL` | The user an action was performed *on* |
| `description` | text, nullable | |
| `old_values` / `new_values` | json, nullable | Masked for known-sensitive keys — see `security.md` |
| `metadata` | json, nullable | Not masked — don't put sensitive data here either |
| `ip_address` | string(45), nullable | |
| `user_agent` | text, nullable | |
| `severity` | string, nullable, default `info` | `info` \| `warning` \| `critical` |
| `created_at` | timestamp | No `updated_at` |

Indexed on `action`, `module`, `(auditable_type, auditable_id)`, plus the
implicit indexes from the `tenant_id`/`actor_user_id`/`target_user_id`
foreign keys.
