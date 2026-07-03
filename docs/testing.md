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

## Testing nested tenant-scoped resources (parent + child ownership)

`EmployeeDocumentApiTest` extends the pattern above for a resource nested
under another (`/employees/{employee}/documents/{document}`):

- Cross-tenant tests need fixtures for **both** the tenant boundary *and*
  the parent-child boundary — e.g. `test_document_must_belong_to_employee_in_route`
  creates two employees *in the same tenant* and confirms a document
  belonging to one is rejected when accessed via the other's route, which
  a tenant-only check wouldn't catch.
- `Storage::fake('local')` in `setUp()` — never touches the real
  filesystem during tests. Use `UploadedFile::fake()->create($name,
  $kilobytes, $mimeType)` for upload tests; passing an explicit
  `$mimeType` matters for negative tests (rejecting a `.exe`), since
  Laravel's fake files don't have real file-signature bytes to detect
  content from.
- For tests that need an *existing* document (not uploading a new one —
  show/download/delete tests), `EmployeeDocumentFactory` writes a real
  fake file to the faked disk in an `afterCreating()` callback, so
  `Storage::disk(...)->download(...)` has something real to stream
  without every test needing its own upload step first.
- **Structural, not just behavioral, middleware assertions are valuable
  too.** `test_all_document_routes_include_tenant_matches_middleware`
  inspects `Route::getRoutes()` directly and asserts `tenant.matches` is
  present on every document route — this catches "someone added a new
  route and forgot the middleware" even if no test happens to exercise
  the specific cross-tenant scenario that middleware protects against.
  Same pattern reused in `EmployeeUserLinkTest::test_all_new_routes_include_tenant_matches_middleware`
  for the three Checkpoint 11 routes (`link-user`, `unlink-user`,
  `me/employee`) — including `me/employee`, which has no `permission:`
  middleware at all, making the `tenant.matches` check the *only*
  structural guard on that route and worth asserting explicitly.

## Testing "safe soft-delete" claims directly, not just that the row disappears

When a checkpoint's design relies on soft delete being non-destructive to
other records (e.g. Checkpoint 9: a soft-deleted `DocumentCategory` must
not break existing `EmployeeDocument` rows referencing it), test the
*actual claim*, not just that the delete request succeeded:

```php
$this->assertSoftDeleted('document_categories', ['id' => $category->id]);
$this->assertDatabaseHas('employee_documents', ['id' => $document->id, 'document_category_id' => $category->id]);
$this->assertNull($document->fresh()->deleted_at);
```

`assertSoftDeleted()` alone doesn't prove the *other* row is unaffected —
that needs its own explicit assertion. See
`DocumentCategoryApiTest::test_category_used_by_active_document_cannot_be_unsafely_hard_deleted`.

## `Rule::exists()` doesn't know about Eloquent scopes — test that gap directly if it matters

If a validation rule uses `Rule::exists()`/`Rule::unique()` against a
table whose model has `SoftDeletes` or a status/active flag, and the
business rule depends on excluding inactive/deleted rows, write a test
that creates an inactive/soft-deleted row and confirms it's rejected —
don't assume the rule "just works" because the model has the scope. This
exact gap existed in Checkpoint 8's code for one checkpoint before
Checkpoint 9's tests caught it.

## Testing multi-step workflows (create → publish → assign → acknowledge)

`PolicyApiTest` introduces a `publishedPolicy(Tenant, User)` helper that
sets up a policy already through the create-version-publish sequence, so
tests focused on assignment/acknowledgement don't need to repeat that
setup inline. When a resource has a real lifecycle (draft → published →
assigned → acknowledged, in this case), prefer a small helper that
produces "a resource in state X" over duplicating the full sequence in
every test — but still write at least one test that exercises the
sequence itself end-to-end (`test_policy_can_be_published`), not only
tests that start from the helper's shortcut.

**Test what "old versions aren't deleted" actually means, not just that
publishing succeeds:** `test_old_published_version_is_archived_not_deleted_on_republish`
asserts both that the old version's `status` became `archived` *and*
that it's `assertNotSoftDeleted` — either assertion alone would pass for
the wrong reason (e.g. a bug that hard-deletes the row would still leave
`status` unqueried-but-gone).

## Testing self-service vs. admin-recorded dual-path actions

`EmployeeUserLinkTest` (Checkpoint 11) tests
`PolicyController::acknowledge()`'s two resolution paths as genuinely
different scenarios, not variations of the same test:

- Self-acknowledgement: caller has a linked employee, submits no
  `employee_id` (or their own) — succeeds with just `policies.acknowledge`.
- On-behalf-of: caller submits a *different* employee's id — must be
  rejected (`403`) for a caller who only holds `policies.acknowledge`,
  and succeeds only when they additionally hold `policies.assign`.
- No link at all: caller has neither a linked employee nor an explicit
  `employee_id` — `422`, distinct from the `403`/`404` cases above.

Whenever an endpoint resolves "who this action is for" from either a
verified session link *or* an explicit request field, test all three
branches (self via link, explicit-and-authorized, explicit-and-
unauthorized) plus the "neither is available" edge case — collapsing
them into fewer tests risks missing exactly the branch that matters for
security.

## Testing a multi-actor workflow with asymmetric trust (Checkpoint 12)

`LeaveRequestApiTest` is the first test file covering a resource where
*different actors are trusted for different state transitions on the
same record* (employee: draft/submit/cancel; HR: approve/reject; nobody:
self-approve). A few patterns worth reusing for the next workflow module:

- **Test the two object-level checks as genuinely different scenarios,
  not the same check twice.** `test_employee_cannot_view_another_employees_leave_request`
  (visibility, expects `404`) and `test_patch_is_owner_only`
  (self-service action ownership, expects `403`) look similar but assert
  different status codes on purpose — see `docs/security.md`'s "Two
  different object-level checks" note. Don't collapse them into one
  test; the status code difference *is* the thing being verified.
- **Test self-approval blocking with a user who holds the approval
  permission**, not a user who lacks it — `test_employee_cannot_approve_own_request`
  grants the acting user `leave.approve` explicitly, then asserts `403`
  anyway, because the failure being tested is the ownership check, not
  the permission gate (a user without `leave.approve` would be blocked
  by route middleware regardless, which wouldn't prove the ownership
  check does anything).
- **Test status-transition rejection using a request already in the
  target-adjacent terminal state**, not an arbitrary invalid one —
  `test_invalid_status_transition_is_rejected` creates an already-
  `approved` request and calls `approve()` again, which is the most
  realistic way this bug would actually manifest (a double-click, a
  retried request), not a synthetic "draft → rejected" case that's less
  likely to happen in practice.
- **Test server-computed values by deliberately sending a wrong one**,
  not by omitting the field — `test_total_days_is_calculated_server_side`
  sends `total_days: 999` alongside real 3-day dates and asserts the
  *ignored* value never reaches the database, which is a stronger claim
  than "the field works when omitted."

## Testing that a sensitive field is masked, not just present in the DB

`test_rejection_reason_is_not_stored_raw_in_audit_log` and
`test_leave_reason_is_not_stored_raw_in_audit_log` don't just check that
an audit log row was created — they read the actual `new_values` JSON
column back and assert the specific field equals `***MASKED***`, plus a
`assertStringNotContainsString($secretText, json_encode($auditLog->new_values))`
belt-and-braces check. `assertDatabaseHas('audit_logs', [...])` alone
(the pattern used for "was an event logged at all" tests elsewhere)
would not catch a masking regression — a row can exist with the raw
secret value still inside it and that assertion would still pass.

## Testing fail-closed logic — assert it fails closed on inputs beyond the obvious case

`ManagerHierarchyTest` covers cycle rejection for both a direct cycle (A
↔ B) and an indirect one (A → B → C → A) as separate tests, not just
the direct case — a cycle-detection implementation that only walks one
hop would pass a "direct cycle" test while still being broken for
anything deeper. When a piece of logic is described as "fail closed,"
write at least one test for the *obvious* failure mode and one for a
less-obvious one (here: an indirect chain) that a shallow implementation
could still pass. `wouldCreateCycle()`'s deeper fail-closed conditions
(cross-tenant/soft-deleted/inactive employee found mid-chain, corrupted-
chain depth cap) are exercised indirectly through the assignment-time
checks that already reject those states before a chain could be built —
see `AssignManagerRequest`; there's no code path in this app that could
construct such a chain to test the walk's defense-in-depth directly, so
those branches are currently unit-untested even though they exist. Worth
a direct unit test on `ManagerHierarchyService` itself if a future
change makes constructing such a chain possible (e.g. a data-migration
tool, or removing one of the assignment-time checks).

## Testing that a structurally-closed write path stays closed (Refinement 3)

`test_general_employee_update_endpoint_cannot_set_manager` sends
`manager_employee_id` through `PATCH /employees/{employee}` (not the
dedicated manager endpoint) and asserts the field is unchanged —
`200`, not a validation error, since the field is silently ignored
rather than rejected (the same "not a validated field" pattern as
`tenant_id`). This is the regression test that makes the "old path is
structurally closed" claim in `docs/security.md` actually verified, not
just asserted in a comment. Whenever a future checkpoint moves a field's
write path from a general endpoint to a dedicated one (the same pattern
used for `employees.user_id` in Checkpoint 11), add this exact shape of
test: prove the *old* endpoint no longer works, not just that the *new*
one does.

## Verifying against the real app, not just the test suite

Because of the SQLite/Postgres split above, checkpoints in this project
have consistently included a live-app verification step alongside the
test suite: `curl` against `https://{subdomain}.peopleos.test` (use
`--ssl-no-revoke` — curl's Windows schannel backend fails on the local
mkcert cert's revocation check, which real browsers don't do), and direct
`psql` queries to confirm seeded/written data. Keep doing this for new
checkpoints — it's caught real bugs the test suite alone didn't.

### A status-code nuance found via live smoke testing, not the test suite (Checkpoint 13)

A live cross-tenant-session smoke test against
`GET /employees/{employee}/direct-reports` returned `404`, where the
same scenario against a parameter-free route (`/me/direct-reports`,
`GET /leave-requests`) returns `403` via `tenant.matches`. Traced to
route-model-binding (`SubstituteBindings`) resolving `{employee}`
through `BelongsToTenant`'s tenant scope *before* `tenant.matches` runs
— the model simply isn't found under the wrong resolved tenant, so
binding throws first. Confirmed via a second live check that this
already held for the pre-existing `GET /employees/{employee}` route
(Checkpoint 6) — not a regression, a pre-existing and consistent
behavior across every `{model}`-bound route that the test suite never
had a reason to exercise (`TenantMatchingMiddlewareTest`'s cross-tenant-
session coverage only uses parameter-free routes). Worth checking for
directly the next time a checkpoint adds a `{model}`-bound route: don't
assume `tenant.matches`'s `403` is what a cross-tenant-session test
against it will see — verify empirically, the same way this was found.

### A real CSRF round-trip against the live app, done correctly (Checkpoint 11)

Hand-rolling the cookie → header CSRF round-trip in bash `curl` is
error-prone: the `XSRF-TOKEN` cookie value must be **percent-decoded but
not further transformed** before being sent back as the `X-XSRF-TOKEN`
header (Laravel decrypts the raw cookie value server-side — the header
must carry the same encrypted blob the cookie held, just URL-unescaped).
A generic bash "urldecode" helper that also converts `+` to space (the
`application/x-www-form-urlencoded` rule, not the general percent-encoding
rule) silently corrupts the token, since the base64-ish encrypted value
legitimately contains literal `+` characters — this produced a `419` that
looked identical to a genuinely missing/invalid token.

**More reliable: drive the round-trip through Laravel's own HTTP client
inside `tinker`**, piped via stdin (`cat script.php | ./artisan.bat
tinker`) rather than passed as a one-line `--execute` string (multi-line
`--execute` strings get mangled by the shell). A `GuzzleHttp\Client` with
a `CookieJar` handles cookie-domain matching and re-sending correctly;
read `XSRF-TOKEN` from the jar, `urldecode()` it (PHP's, not a hand-rolled
bash version), and set it as `X-XSRF-TOKEN` on the next request. This
verified the full `login → link-user → me/employee → unlink-user` flow
end-to-end over real HTTPS with genuine CSRF enforcement, plus a
cross-tenant session-reuse check (an admin's session cookie sent to a
*different* tenant's subdomain, confirming `tenant.matches` still
rejects it with `403` over real HTTP, not just in the test client).

**Tenant-owned records created via `tinker` for smoke-test fixtures**
must set `tenant_id` directly on the model instance (not via
`create(['tenant_id' => ...])`, which silently drops it — see
`docs/architecture.md`'s CLI/tinker gotcha note) since no `Tenant` is
bound in the container outside a real HTTP request.

## Known limitations

- No CI pipeline configured yet — tests are run locally only.
- No test coverage reporting configured yet.
- Feature tests dominate; very few pure unit tests exist so far, since
  most logic to date (tenant resolution, RBAC guards, audit logging) is
  most meaningfully verified at the HTTP/integration level.
