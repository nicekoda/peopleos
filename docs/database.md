# Database

PostgreSQL. Migrations only — no manual schema changes.

## Conventions

- **Primary keys**: ULIDs (`$table->ulid('id')->primary()`) for
  tenant-facing tables (sortable, safe to expose in URLs, avoids leaking
  sequential counts). Laravel's default auto-increment `id()` remains
  acceptable for framework-internal tables (e.g. `users`, `jobs`) unless a
  table is tenant-owned or externally referenced.
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
