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

**Middleware order matters, and got it wrong twice — both times fixed
before any real damage, but both were genuine bugs, not theoretical
concerns.**

1. **`ResolveTenant` vs. route model binding** (Checkpoint 6). Registered
   with `prependToGroup('web', ...)`, not `appendToGroup` — must run
   *before* `SubstituteBindings` (Laravel's route-model-binding
   middleware, part of the default `web` group stack), otherwise a
   tenant-scoped model's `{param}` route binding would resolve before any
   tenant is bound in the container, meaning `BelongsToTenant`'s global
   scope wouldn't be active yet for that lookup. Originally registered
   with `appendToGroup` (Checkpoint 2); the bug went undetected until
   Checkpoint 6 built a route using tenant-scoped implicit binding.

2. **Tenant identification vs. tenant *authorization*** (Checkpoint 7).
   `ResolveTenant` correctly identifies *which* tenant a request is for
   (from the `Host` header) — but identifying the tenant is not the same
   as confirming the *authenticated user* should be allowed there. A
   session cookie shared across all subdomains (`SESSION_DOMAIN`) meant an
   authenticated tenant-A user's browser would automatically send valid
   credentials to tenant-B's subdomain too, and nothing checked that
   mismatch. Fixed with `App\Http\Middleware\EnsureTenantMatchesAuthenticatedUser`
   (`tenant.matches`), applied per-route after `auth` and before
   `permission:` — see [`security.md`](security.md#tenant-session-isolation--a-real-vulnerability-found-in-checkpoint-7)
   for the full story and the final middleware-order rule.

**The pattern, stated generally:** *identifying* context (which tenant
does this URL belong to) and *authorizing* against that context (should
this specific authenticated user be here) are two different checks, and
both are required. Getting the first right doesn't imply the second is
covered. If you add another `web`-group middleware that needs to run
before route model binding, check its position against
`SubstituteBindings` explicitly — don't assume `appendToGroup` is always
safe. If you add a new authenticated route, confirm it includes
`tenant.matches` — it's opt-in per route, not automatic.

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

## Employee Records

The first real tenant-owned HR business module — see
[`api.md`](api.md), [`database.md`](database.md#employees), and
[`security.md`](security.md#employee-records) for the endpoint reference,
table design, and permission/audit details respectively.

Pattern worth reusing for future modules — three independent layers, not
one:

1. **`tenant.matches` middleware** — does the authenticated user belong to the tenant this request resolved to at all? (Checkpoint 7 fix.)
2. **`BelongsToTenant` global scope** — queries and route-model-binding filtered to the resolved tenant, active before binding resolves (Checkpoint 6 fix).
3. **Explicit controller check** (`ensureBelongsToCurrentTenant()` in `EmployeeController`) — defense in depth beyond the global scope.

If any one of these is ever weakened by a future change, the other two
still hold. Every future tenant-scoped module should include all three,
not just whichever one is most convenient to remember.

## Document Repository

Second tenant-owned business module — see [`api.md`](api.md),
[`database.md`](database.md#document_categories), and
[`security.md`](security.md#document-repository) for the full design.

**Extends the three-layer pattern above to four**, because documents are
*nested* under employees (`/employees/{employee}/documents/{document}`),
not a top-level resource: `tenant.matches` → `BelongsToTenant` global
scope → employee-belongs-to-tenant check → **document-belongs-to-that-
specific-employee check**. A document ID that's valid for the current
tenant but belongs to a *different* employee than the one in the route
must still be rejected — the tenant-level checks alone don't catch this,
since it's a same-tenant, different-parent-resource case.

**Private storage is non-negotiable and verified, not assumed.** Files
go to `storage/app/private` only; this was confirmed directly (a real
file written through the actual controller code path, checked to exist
on disk but not under `public/storage`) rather than inferred from
Laravel's disk configuration alone.

## Document Category Management

Third tenant-owned resource with a management API — top-level (not
nested, unlike `EmployeeDocument`), so back to the standard three-layer
pattern: `tenant.matches` → `BelongsToTenant` global scope → explicit
controller tenant-ownership check.

**Worth internalizing as a general lesson from this checkpoint:**
`Rule::exists()` (Laravel's raw-DB validation rule) does not know about
Eloquent model scopes — including `SoftDeletes`. Any future validation
rule referencing a tenant-owned table that has soft deletes or a
status/active flag must explicitly filter for that in the rule's `where`
closure; it will not happen automatically just because the model has
`SoftDeletes`. This was a real gap in Checkpoint 8's code, found and
fixed in Checkpoint 9 — see
[`security.md`](security.md#a-real-checkpoint-8-validation-gap-found-and-fixed).

**Also worth internalizing:** `Model::create()` does not backfill
database column defaults into the in-memory model instance for
attributes omitted from the create array. If a resource/response reads
an attribute assuming it reflects the DB default when unset, default it
explicitly in the controller before `create()` — don't rely on the
column's schema default alone. Found the same way: a real crash on the
first test run, not a hypothetical.

## Policy Management

Builds directly on Employee Records and the Document Repository — see
[`api.md`](api.md) and [`security.md`](security.md#policy-management) for
the full design.

**The recurring lesson from this checkpoint: identity gaps propagate.**
`User` and `Employee` were deliberately left unlinked all the way back at
Checkpoint 3 (documented then as a known limitation). This checkpoint is
where that gap first had a concrete, security-relevant consequence — the
acknowledgement endpoint can't verify "is this employee the current
user," so it had to be designed as admin-recorded rather than genuine
self-service, and a role grant had to deliberately deviate from the
spec's own suggestion as a result. Worth remembering for the next module
that touches employee self-service (Leave Management's own-request flows
will hit the exact same gap).

**A second `$fillable` bug, from the same root cause as one class of bug
already seen twice.** `Employee` and `DocumentCategory` had silently
dropped `created_by`/`updated_by` since Checkpoints 6 and 9 — found and
fixed here, alongside getting it right in the two new models this
checkpoint introduces. The lesson generalizes: **a comment saying "not
accepted as request input" is not the same claim as "excluded from
`$fillable`."** The former is about what a `FormRequest`'s validated()
output contains; the latter is about what `Model::create()`/`fill()` will
actually persist. Conflating them silently drops legitimate
controller-set values. Worth a deliberate audit of every model's
`$fillable` list against its controller's actual `create()`/`update()`
calls before the next checkpoint introduces more.

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
