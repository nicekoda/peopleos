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
- **`created_by` / `updated_by`**: added once a `users` table exists
  (Checkpoint 3+). Not present on `tenants` yet — there is no user system
  to attribute tenant creation to at this stage.

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
