# Testing

## Running tests

```bash
./artisan.bat test
./artisan.bat test --filter=AuditLoggingTest
```

Uses PHPUnit directly (`vendor/bin/phpunit` also works, same `PHPRC`
scoping applies — see [`README.md`](../README.md)).

## Database: SQLite in-memory, not PostgreSQL

`phpunit.xml` overrides `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` for
the whole test run — a deliberate Laravel default for speed, not
something this project changed. This means:

- Migrations must work on **both** PostgreSQL and SQLite. Anywhere a
  migration needs Postgres-specific syntax (raw `CHECK` constraints —
  SQLite can't `ALTER TABLE ADD CONSTRAINT`), guard it with
  `if (DB::getDriverName() === 'pgsql')` and rely on an app-layer
  equivalent (an Eloquent `saving` guard) for SQLite coverage instead. See
  `roles`/`users` migrations for the pattern.
- Partial unique indexes (`CREATE UNIQUE INDEX ... WHERE ...`) work on
  **both** drivers, so those aren't guarded — see the `roles` migration.
- Passing tests don't prove Postgres-specific behavior. When in doubt,
  verify directly against the real local Postgres database too (`psql`
  queries, or hitting the live app via `curl`) — this has caught real
  issues during development (e.g. Checkpoint 3's JSON-vs-redirect bug on
  `ValidationException` only surfaced when tested against real HTTP
  behavior, not the always-JSON-friendly test client).

## CSRF is automatically bypassed in tests

Laravel's CSRF middleware checks `App::runningUnitTests()` and skips
verification when true (`APP_ENV=testing`). This means `$this->post(...)`
in a feature test works against CSRF-protected routes without any extra
setup — but **the real running app enforces CSRF normally**. Don't take a
passing test as proof a route works over raw HTTP; if you need to verify
that, do it against the live app with a real CSRF token round-trip
(cookie → header), not just curl without one.

## Testing subdomain-based tenant resolution

`ResolveTenant` reads the request's `Host` header, so tests that need a
specific tenant context must hit a full URL with the right host, not a
bare path:

```php
$this->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/');
```

Requests to the bare base domain (no tenant) or a reserved subdomain use
`config('tenancy.base_domain')` alone.

## Testing permission-protected routes without a real endpoint yet

For modules with no real routes yet, register an ad-hoc route directly
inside the test method rather than adding a fake permanent route to
`routes/web.php`:

```php
Route::middleware(['web', 'auth', 'permission:employees.view'])
    ->get('/__test/protected', fn () => response()->json(['ok' => true]));
```

See `RbacTest::test_middleware_allows_user_with_permission` for the full
pattern (including `actingAs()` + hitting the tenant's subdomain). Once a
module has real endpoints (Employee Records now does), test against
those directly instead — see `EmployeeApiTest`.

## Testing tenant-scoped CRUD endpoints

`EmployeeApiTest` establishes the pattern for testing a tenant-isolated
resource end-to-end:

- A `userWithPermission(Tenant $tenant, string ...$permissionKeys)` helper
  that creates a user, a role scoped to that tenant, grants the given
  permissions to the role, and assigns it — avoids repeating RBAC setup
  boilerplate in every test.
- Cross-tenant isolation tests always create fixtures in **two** tenants
  (`$tenantA`/`$tenantB`) and assert the acting user's tenant can't see,
  update, or delete the other tenant's records — not just "no data
  exists," since an empty result can pass for the wrong reason.
- `assertNotFound()` (404), not `assertForbidden()` (403), for cross-tenant
  access attempts — don't reveal that a record exists in another tenant.

## Verifying against the real app, not just the test suite

Because of the SQLite/Postgres split above, checkpoints in this project
have consistently included a live-app verification step alongside the
test suite: `curl` against `https://{subdomain}.peopleos.test` (use
`--ssl-no-revoke` — curl's Windows schannel backend fails on the local
mkcert cert's revocation check, which real browsers don't do), and direct
`psql` queries to confirm seeded/written data. Keep doing this for new
checkpoints — it's caught real bugs the test suite alone didn't.

## Known limitations

- No CI pipeline configured yet — tests are run locally only.
- No test coverage reporting configured yet.
- Feature tests dominate; very few pure unit tests exist so far, since
  most logic to date (tenant resolution, RBAC guards, audit logging) is
  most meaningfully verified at the HTTP/integration level.
