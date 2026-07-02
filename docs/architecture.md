# Architecture

## Multi-Tenancy

PeopleOS is multi-tenant: one Laravel application and one PostgreSQL
database serve every client ("tenant"). Isolation strategy: **shared
database, shared schema, `tenant_id` column** on every tenant-owned table.
This is a deliberate choice (per the Database and Multi-Tenancy Standard),
not the only option — dedicated database/schema per tenant may be
considered later if a client requires it.

### Tenant identification: subdomain-based

Each tenant is reached at `{subdomain}.{base_domain}` (e.g.
`uesl.peopleos.test` locally; production base domain via `APP_DOMAIN`).
`App\Http\Middleware\ResolveTenant` runs in the `web` middleware group on
every request:

1. Reads the request's `Host` header.
2. If it equals the bare base domain, or a reserved subdomain
   (`config('tenancy.reserved_subdomains')`), no tenant is bound — this is
   a platform-level request (super admin console, marketing, etc. — not
   built yet).
3. Otherwise, the leading label is looked up against `tenants.subdomain`.
   No match → 404. Match but tenant not `active` → 403. Match and active →
   the `Tenant` model is bound into the container
   (`app()->instance(Tenant::class, $tenant)`) for the rest of the request.

### Tenant-owned models: `BelongsToTenant`

Every tenant-owned Eloquent model must use
`App\Models\Concerns\BelongsToTenant`. It:

- Adds a global scope filtering all queries to the tenant currently bound
  in the container.
- Auto-fills `tenant_id` on `creating` from the bound tenant, if not
  already set.

Outside a resolved-tenant context (CLI, artisan commands, tests, seeders,
platform-level requests), **no automatic scoping or filling occurs** —
callers must set `tenant_id` explicitly. This is intentional: CLI tooling
often needs to operate across tenants or before any tenant is known.

**This is enforcement, not the only safeguard.** Every controller/query
should still be written as though the global scope might not apply (e.g.
CLI contexts) — see the Access Control Rules in the master constitution:
every endpoint must independently verify tenant membership before acting
on a record.

**`User` is a deliberate exception.** It does not use `BelongsToTenant` —
see [`security.md`](security.md#why-user-doesnt-use-belongstotenant) for
why (login must find users before a tenant is "current"; platform admins
need cross-tenant visibility; tenant assignment must be explicit, not
inferred from the request's subdomain).

## Authentication

See [`security.md`](security.md) for the user model, platform admin vs.
tenant user rules, and the login flow.

## Authorization (RBAC)

Roles and permissions follow the same platform-vs-tenant split as `User`
and `Tenant` — see [`security.md`](security.md#rbac) for the full design.
Two things worth knowing at the architecture level:

- **Tenant roles are per-tenant rows, not shared templates.** This is
  what makes cross-tenant role/permission leakage structurally prevented
  rather than just conventionally avoided.
- **`hasPermission()` is the single source of truth**, reused by the
  `permission:` middleware and by Laravel's native `can()`/`@can()` (via
  a `Gate::before()` hook) — there's exactly one place permission logic
  lives, not three parallel implementations that could drift.

## Audit Logging

`AuditLogger` (`app/Services/Audit/AuditLogger.php`) is the single
reusable entry point every module should use to record security-relevant
events — see [`security.md`](security.md#audit-logging) for the full
design, what's currently wired up, and the masking rules. Two
architectural points worth knowing here:

- **`AuditLog` is append-only at the model layer** — `save()` on an
  existing row and `delete()` both throw, not just "no UI exists to do
  it yet."
- **`tenant_id` is always explicit**, same rule as `User` — no
  `BelongsToTenant` auto-fill, since audit events happen in contexts
  (login, CLI, seeders) where an ambient bound tenant would be unreliable.

Future modules (Employee Records onward) should call `AuditLogger::log()`
or `AuditLogger::logFor()` directly from controllers/model methods for
any sensitive action — don't build a parallel logging mechanism.

## Internal IDs vs. Public-Facing References

Internal database IDs may remain bigint (see
[`database.md`](database.md)) — that's a storage detail, not a security
boundary. The actual rule: **public-facing links, invitation links,
external portal links, document links, and any other reference exposed
outside an authenticated session must never expose a raw internal ID.**
Future modules needing a public-facing identifier should use a secure
token, a separate ULID/UUID public-ID column, a signed URL, or a
configured reference code — not the row's primary key.

## Local Development Environment

See [`README.md`](../README.md) for PHP extension scoping (CLI vs. Apache
`mod_php`) and the local HTTPS/subdomain setup (mkcert wildcard cert,
Laragon vhost split, hosts file requirements).
