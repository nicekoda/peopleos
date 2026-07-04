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

## Testing an authorization tightening — prove the old sufficient condition is now insufficient (Checkpoint 14)

When a permission that used to be *sufficient* on its own becomes
merely *necessary* (Checkpoint 14: `leave.approve`/`leave.reject` alone
used to authorize tenant-wide action; now also requires `leave.view_all`
or a direct-management relationship), the single most important test is
the negative one: a caller holding *only* the old sufficient condition,
with none of the new qualifying scopes, must now be rejected.
`ManagerScopedLeaveApprovalTest::test_user_with_approve_but_no_hr_scope_and_no_manager_relationship_cannot_approve`
(and its reject equivalent) exist specifically to prove this — without
them, a regression that silently reverted the scope check back to
"permission alone is enough" would pass every other test in the suite
(HR/Admin and Line Manager scenarios both still work fine under the old,
broken rule) and only this test would catch it.

**Re-running an existing test file after an authorization change is not
optional — it's how you find what the change actually broke.**
Tightening `approve()`/`reject()` broke 6 pre-existing
`LeaveRequestApiTest` fixtures that granted `leave.approve`/`leave.reject`
alone (previously sufficient, Checkpoint 12). Found by literally running
`LeaveRequestApiTest` after the change and reading the failures — not by
reasoning about which tests "should" be affected. Same pattern as
Checkpoint 11's `PolicyApiTest` fix: when a checkpoint changes existing
authorization logic, re-run the affected module's full test suite before
writing any new tests, and fix what breaks by adding the *now-required*
permission to the fixture, not by loosening the new check.

## A nested-factory tenant-scoping trap, and `Factory::recycle()` as the fix (Checkpoint 15)

`LeaveRequestFactory`'s `leave_type_id => LeaveType::factory()` default
creates a brand-new `LeaveType` belonging to its **own** randomly
generated `Tenant` — not the tenant of whatever `tenant_id` override you
pass to `LeaveRequest::factory()->create([...])`. This is harmless right
up until something actually dereferences the relation
(`$leaveRequest->leaveType`) from within a real tenant-scoped request:
`BelongsToTenant`'s global scope then silently filters the mismatched
leave type out, and the relation resolves to `null`.

This checkpoint's `submit()`/`approve()`/`reject()`/`cancel()` are the
first code to actually load `->leaveType()` this way (to check
`max_days_per_year`), and it broke 15 existing tests across
`LeaveRequestApiTest` and `ManagerScopedLeaveApprovalTest` — every one
of them created a `LeaveRequest` with an explicit `tenant_id` override
but no matching `leave_type_id` override, so the factory silently
created an orphaned-relationship fixture that had simply never been
exercised before.

**The fix: `Factory::recycle($tenant)`, not touching 50 individual call
sites.** Laravel's `recycle()` tells a factory "when resolving any
nested factory default for this model class, reuse this specific
instance instead of creating a new one" — it cascades through the whole
dependency tree for that `create()` call, so
`LeaveRequest::factory()->recycle($tenant)->create(['tenant_id' =>
$tenant->id, ...])` makes the nested `LeaveType::factory()` (and
`Employee::factory()`, if not otherwise overridden) reuse `$tenant`
instead of generating their own. This is the general answer whenever a
factory's default nests another factory that itself defaults to a new
`Tenant::factory()` — reach for `recycle()` rather than overriding every
nested relation's fields by hand at every call site.

**Takeaway for future checkpoints**: any new relation dereferenced for
the first time from inside a real (not just unit-tested-in-isolation)
tenant-scoped code path is worth checking against existing test fixtures
built with partial `tenant_id` overrides — the mismatch is silent until
something actually loads the relation.

## Testing balance enforcement: locking, idempotency, and rollback (Checkpoint 15)

- **Test concurrency protection with a *sequential* two-submit scenario,
  not a literal concurrent one.** `test_balance_reservation_uses_locking_and_prevents_overspend`
  doesn't spin up parallel requests — it submits two draft requests one
  after another against a 5-day balance (3 + 3 days) and asserts the
  second is rejected. This doesn't prove the lock prevents a true race
  under real concurrency, but it does prove the *arithmetic* correctly
  rejects overspend once the first reservation is committed — the
  `lockForUpdate()` mechanism itself is a well-established Postgres/
  MySQL primitive, not something this app needs to reprove from
  scratch; what's worth testing here is that the application logic
  actually uses the freshly-locked value rather than a stale one.
- **Test the rollback claim directly (Refinement 8), not just the
  error status code.** `test_submit_exceeding_available_balance_is_rejected`
  asserts both the `422` response *and* that `$leaveRequest->fresh()->status`
  is still `draft` — a bug that changed the leave request's status
  before checking balance (or that failed to roll back a partial write)
  would still return `422` from the balance check but leave the request
  incorrectly `pending`. The status code alone wouldn't have caught that.
- **Test the specific idempotency failure mode, not just "the happy
  path works twice."** `test_invalid_status_transition_does_not_change_balance`
  approves a request, then approves it *again*, and asserts `used_days`
  didn't double — this is the concrete manifestation of "an already-
  approved request must not consume balance again" from Refinement 1,
  more convincing than asserting the second call merely returns `409`.
- **Test that a no-op path is actually a no-op, not just unreachable.**
  `test_cancel_draft_request_does_not_affect_balance` confirms cancelling
  a `draft` (which never reserved anything) leaves no `leave_balances`
  row created at all — proving the "was this request actually pending"
  check in `cancel()` does something, not merely that it doesn't crash.

## Testing the frontend (Checkpoint 16): backend-verified, not a JS test runner

No Jest/Vitest is configured — frontend correctness is verified three
ways instead:

1. **`npx tsc --noEmit`** — full TypeScript type-checking. `vite build`
   alone does *not* fully type-check (it transpiles); run this
   separately, and after any change to shared types
   (`resources/js/types/index.d.ts`) or a component's props.
2. **`npm run build`** — the production Vite build must succeed.
3. **Backend feature tests asserting the Inertia response shape** —
   `assertInertia(fn ($page) => $page->component('Dashboard'))` proves
   the correct page component is being rendered; reading
   `$response->viewData('page')` (the raw page array Inertia's test
   response exposes) lets a test inspect the actual shared-prop payload
   directly, which is how sensitive-field and tenant-isolation checks
   are verified (see below) — there's no need for a JS runner to prove
   the *data* reaching the frontend is correct, only that the frontend
   *renders* it correctly, which isn't covered yet (see Known
   limitations).

## An authentication-endpoint content-negotiation change exposed 3 tests that assumed "always JSON" (Checkpoint 16)

`AuthenticatedSessionController::store()`/`destroy()` now branch on
`$request->expectsJson()` (JSON for API clients, a redirect for real
browser/Inertia posts) instead of always returning JSON. Three
pre-existing test files called these routes with **bare** `$this->post()`/
`$this->get()` (no explicit `Accept` header) and asserted `200`/`401`
outright:

- `AuthenticationTest` (7 call sites) — converted to `postJson()`, since
  these tests were always exercising the JSON contract in spirit (they
  only check status codes, never inspect HTML), just never had to say so
  explicitly because nothing else existed.
- `AuditLoggingTest::test_login_success_creates_audit_log`/
  `test_logout_creates_audit_log` — same fix.
- `TenantMatchingMiddlewareTest::test_unauthenticated_request_is_rejected_by_auth_not_tenant_matches` —
  split into two tests: one converted to `getJson()` (proving the JSON
  contract still gets a clean `401`), one new
  (`test_unauthenticated_browser_request_redirects_to_login_not_tenant_matches`)
  proving the *new*, correct behavior for a real browser request
  (redirect to `/login`, not a crash) — this scenario was genuinely
  untestable before Checkpoint 16, since no login route existed for
  Laravel's default redirect-fallback to resolve.

**The lesson, generalized**: a bare `$this->post()`/`$this->get()` test
call implicitly assumes "this endpoint responds the same way regardless
of what I ask for." That assumption is only ever true by accident — when
an endpoint later needs to serve two audiences differently (as `/login`
now does), tests written against the accidental behavior need to
declare, explicitly, which contract they're testing (`postJson()` for
"I want the JSON contract," bare `get()`/`post()` for "I'm a real
browser"). This is the same shape of fix as Checkpoint 11's `PolicyApiTest`
and Checkpoint 14's `LeaveRequestApiTest` — an authorization/behavior
tightening breaks some existing tests, and the fix is to make each
test's own expectations explicit, not to weaken the new behavior.

## Testing shared Inertia props directly, not just that a page renders

`$response->viewData('page')` returns the same page array Inertia's own
`assertInertia()` macro inspects internally, but as a plain PHP array —
useful for assertions `assertInertia()`'s fluent API doesn't cover
directly, like "no key anywhere in this JSON contains `password`":

```php
$page = $response->viewData('page');
$propsJson = json_encode($page['props']);
$this->assertStringNotContainsString('password', $propsJson);
```

Test the *specific* refinements a shared-props design promises, not just
"the page loaded": `test_platform_super_admin_does_not_receive_tenant_context`
asserts `tenant` is exactly `null` for a Platform Super Admin (not an
empty array, not a placeholder tenant); `test_tenant_user_shared_props_reflect_only_their_own_tenant`
asserts the shared tenant `id` matches the acting user's own tenant and
explicitly does *not* match a second, unrelated tenant created in the
same test — a test that only checked "the tenant field is present"
would pass even if it were leaking the wrong tenant's data.

## Testing a client-side-fetching Inertia page: only the ID crosses the server/client boundary (Checkpoint 17)

`EmployeeUiController::show()`/`edit()` pass only `employeeId` as an
Inertia prop — the actual employee record arrives via a separate,
client-side call to `/api/v1/employees/{id}`. This has a direct testing
consequence: **there is no new sensitive-field-leak surface to test at
the Inertia-props layer for these pages**, since no employee data ever
flows through them. `EmployeeUiTest::test_show_page_props_contain_only_employee_id_not_employee_data`
confirms this structurally — it asserts the page's props contain
exactly `employeeId` (beyond the standard shared `auth`/`tenant`/
`errors`) and that a known sensitive value never appears anywhere in
the serialized props — but the actual masking logic being correct is
still fully the responsibility of (and already covered by)
`EmployeeApiTest` from Checkpoints 6/7, since that's the endpoint that
actually decides what the frontend will render. Don't re-test masking
logic at the web-route layer just because a new page exists — test that
the *new* thing (the page passes only an ID) is true, and trust the
existing API test suite for everything downstream of that ID.

## What a JS-runner-free frontend checkpoint can and cannot claim to test (Checkpoint 17)

With no Jest/Vitest configured, a module UI checkpoint's "tests
required" list splits cleanly into two categories — say so explicitly,
don't blur them:

- **Backend-testable** (permission gating per route, guest redirects,
  tenant isolation via route-model-binding, the exact prop shape passed
  to each page): ordinary Laravel feature tests, `assertInertia()`,
  `$response->viewData('page')`. `EmployeeUiTest` covers all of this for
  the Employee Records UI.
- **Not backend-testable** (does the Create button actually not render
  for a user without the permission, does a `422` actually populate the
  right input's error text, does the delete confirmation dialog appear):
  these require a real browser/JS test runner, which doesn't exist yet.
  Verified instead via `tsc --noEmit` (the component compiles against
  its prop/state types correctly), `npm run build` (the whole app
  bundles without error), and a live HTTPS smoke test exercising the
  actual flow end-to-end (login → list → create → detail → edit →
  delete, plus a forbidden-field payload and a cross-tenant session
  request) — documented as smoke-test verification, not claimed as
  automated test coverage. If a future checkpoint adds Vitest + React
  Testing Library, these are exactly the cases to backfill first.

## Leave Management UI: same client-side-fetching test shape, one new wrinkle (Checkpoint 18)

`LeaveUiTest` mirrors `EmployeeUiTest` exactly (guest redirects,
permission gating per route, cross-tenant `404`, the
`leaveRequestId`-only prop assertion) — see "Testing a client-side-
fetching Inertia page" above, the same reasoning applies unchanged. The
one new wrinkle: `test_show_page_props_contain_only_leave_request_id_not_leave_data`
seeds the leave request with a genuinely sensitive `reason` value
("Confidential medical procedure.") and asserts it's absent from the
serialized props — a slightly stronger version of Checkpoint 17's
generic "no employee data" assertion, chosen because `reason` is exactly
the kind of value that would be easy to accidentally leak if a future
edit ever changed `LeaveUiController::show()` to pass more than just the
ID "for convenience."

### The manager-scope approval flow was live-tested this checkpoint, not left as backend-only (Refinement 7)

Checkpoint 14 already proved the *authorization logic*
(`ManagerHierarchyServiceTest`, `ManagerScopedLeaveApprovalTest`) at the
API layer. What hadn't been exercised was whether the **UI** correctly
reaches that logic end-to-end. This checkpoint's live smoke test added a
Line Manager user linked to an employee record, made another employee
their direct report, and drove the full flow through real HTTPS pages:
login as the manager → `/leave/{report's-request-id}` (`200`, Approve
button reachable) → approve (`200`, status updates) → `/leave/{unrelated-
employee's-request-id}` (`404`, cross-tenant-style "don't reveal
existence" — actually same-tenant-but-out-of-scope, see "Visibility
scope" in `docs/api.md`) → attempting to approve that same unrelated
request directly via its API endpoint (`403`). Both outcomes matched
expectations exactly on the first run. This is the kind of case worth
live-testing specifically *when practical*, per your Refinement 7 — the
backend test suite proves the rule is correct in isolation; the smoke
test proves the UI doesn't accidentally create a path around it (e.g. a
stale cached button, a client-side scope guess that turns out wrong in
the *permissive* direction).

## Document Repository UI: a second object-level check needs its own dedicated test (Checkpoint 19)

`EmployeeDocumentUiTest` mirrors `EmployeeUiTest`/`LeaveUiTest` for the
usual set (guest redirects, permission gating, cross-tenant `404`, the
IDs-only prop assertion) — but this checkpoint's routes are nested two
levels deep (`/employees/{employee}/documents/{document}`), which
introduces a failure mode the single-model routes don't have:
`test_same_tenant_wrong_employee_document_returns_404` creates two
employees in the *same* tenant, uploads a document for employee B, and
asserts that requesting it through employee A's route 404s. This is
deliberately a separate test from the cross-tenant case
(`test_cross_tenant_document_id_returns_404`) — a route-model-binding
scoped correctly to the tenant would happily resolve `{document}` here
(it *is* a valid document in this tenant); only the explicit
`ensureDocumentBelongsToEmployee()` ownership check catches it. Any
future checkpoint that nests a route two levels under two different
parent models should write this exact test shape rather than assuming
tenant isolation alone covers it.

### A pre-existing permission gap needed fixture verification, not just a role-seeder diff (Refinement 1)

Granting `document_categories.view` to HR Manager/Employee in
`RoleSeeder` is a seed-data change, not exercised by
`EmployeeDocumentUiTest` (which builds its own throwaway roles/permissions
per test, the same pattern every UI test file in this project uses —
see `userWithPermissions()` helpers). The actual effect of the seeder
change was confirmed two different ways: a `tinker` script asserting
`hasPermission('document_categories.view')` on the real seeded
`hr.manager@uesl.peopleos.test`/`employee@uesl.peopleos.test` accounts
before the live smoke test, and the smoke test itself successfully
calling `GET /api/v1/document-categories` as HR Manager (`200`, where it
would have `403`'d before this checkpoint). Worth remembering: a
role-seeder change needs its effect verified against the actual seeded
demo accounts, since the throwaway-role test pattern everywhere else in
this project doesn't touch `RoleSeeder` at all and wouldn't catch a
regression there.

### The live smoke test surfaced a role-mapping fact worth knowing before writing the next fixture

The first delete-step attempt in this checkpoint's live smoke test used
`hr.manager@uesl.peopleos.test` and got a `403` — not a bug, but a
reminder that HR Manager was never granted `documents.delete`
(`RoleSeeder`, Checkpoint 8: HR Manager holds `documents.view`/`upload`/
`download`/`approve`, not `delete`; only Tenant Admin does). The smoke
test was corrected to use `admin@uesl.peopleos.test` for the delete
step, which then succeeded (`200`) and the subsequent `GET` on the
deleted document correctly returned `404` on both the API and the web
detail-page route. Worth checking a role's actual seeded permission set
directly (`hasPermission()` in `tinker`, or just re-reading
`RoleSeeder`) before assuming a demo account holds a given permission —
the "HR Manager: all document permissions" assumption would have been
wrong here.

## Policy Management UI: testing a backend gap fix separately from the UI that needed it (Checkpoint 20)

This checkpoint added one real backend endpoint mid-flow — `GET
/api/v1/policies/{policy}/versions` — after flagging it as a genuine
blocker and getting explicit approval (see
`docs/architecture.md#policy-management-ui-checkpoint-20`). It was
tested in two separate places, deliberately: `PolicyApiTest` gained 4
new tests covering the endpoint itself (permission gating, tenant
isolation, and — the one worth calling out —
`test_versions_endpoint_only_returns_versions_for_the_requested_policy`,
which creates two policies in the *same* tenant, each with its own
version, and asserts policy A's version list never contains policy B's
version ID). `PolicyUiTest` then only needed to prove the *page routes*
around this new data source behave correctly (permission gating, guest
redirects, cross-tenant 404, IDs-only props) — it does not re-test the
versions endpoint's own scoping logic, since that's already proven at
the API layer. This mirrors the general rule from Checkpoint 17's
testing notes: don't re-test a lower layer's correctness at a higher
layer just because a new page exists — test that the new *page* does
its part, and trust the API test suite for the data it's built on.

### Multi-page cross-tenant and props-only-IDs assertions, written as loops (Checkpoint 20)

Unlike every prior module (which has a single detail page), Policy
Management has *five* `{policy}`-bound routes (`show`, `edit`,
`versions/create`, `assign`, `acknowledgements`). Rather than writing
five near-identical cross-tenant tests and five near-identical
props-assertion tests,
`test_cross_tenant_policy_id_returns_404_on_every_bound_route` and
`test_bound_pages_props_contain_only_policy_id` each loop over all five
paths within one test. This is a deliberate divergence from the
per-route test shape every earlier module UI test file used — worth
following for any future module that grows this many bound routes
per resource, rather than mechanically copy-pasting five separate test
methods that would all fail (or pass) for the same underlying reason.

### The live smoke test's self-acknowledgement check needed a real assigned-employee round trip, not just a `200`

Confirming Refinement 5 (acknowledgement stays self-scoped) needed more
than checking the acknowledge call returns `200` — a `200` alone
wouldn't distinguish "acknowledged as myself" from "accidentally
acknowledged as someone else because a stray `employee_id` leaked into
the request." The smoke test's `POST .../acknowledge` was sent with an
explicitly empty JSON body (`[]` in the PHP script, serializing to `{}`)
and the response's `acknowledgement_method` was checked directly:
`"web"` (self-service) rather than `"admin_recorded"`, confirming the
resolved employee really was the caller's own linked record, not just
that *some* acknowledgement succeeded.

## Dashboard: testing absence is as important as testing presence (Checkpoint 21)

Every prior module UI checkpoint's tests mostly proved *presence*: does
a permission holder get the page/data? The dashboard inverts the
emphasis, because its core rule is about what's *absent*: `dashboard.view`
must never leak a card the viewer hasn't separately earned. `DashboardApiTest`
reflects this directly —
`test_dashboard_view_alone_grants_no_module_cards` asserts an empty
`cards`/`recent_items` array for a `dashboard.view`-only user (even with
real employee data seeded in the tenant), and
`test_user_without_employees_view_does_not_receive_employee_count`,
`test_line_manager_receives_only_team_scoped_leave_summary` (which
asserts `total_employees` is *entirely absent* from the response, not
just zero), and `test_employee_user_receives_only_self_service_items`
(asserts `policies_pending_acknowledgement`, `direct_reports`, and
`total_employees` are all absent for a plain Employee) all assert
`assertArrayNotHasKey()` on specific card keys, not just check the
values of cards that are present. A response shape test that only
checks "the cards I expect are there" would have silently let an
over-broad card slip through undetected — the two checks are testing
different failure modes.

### Extracting a private method into a service: the test suite is the proof, not a fresh manual review

`LeaveVisibilityService` is a verbatim copy of `LeaveRequestController`'s
previous private `visibleEmployeeIds()` method. Rather than manually
re-verifying its correctness after the move (easy to do wrong — subtle
behavior differences in extraction are exactly the kind of thing a human
review misses), the actual verification was: run the complete
pre-existing Leave test suite (`LeaveRequestApiTest`,
`ManagerScopedLeaveApprovalTest`, `LeaveUiTest`, and everything else
matching `Leave` — 123 tests) immediately after the extraction, before
writing anything new that depends on the service. All 123 passed
unchanged, with no test modifications needed — this is the strongest
available evidence the extraction didn't alter behavior, stronger than
a manual code-diff review would have been, since it's the same tests
that were written against the *original* implementation's intended
behavior.

### A live smoke test that deliberately reused a session cookie across tenants, via a shared CookieJar

Confirming Refinement 1's tenant-isolation requirement for the new
dashboard endpoint needed the classic Checkpoint 7 attack shape: an
authenticated session from tenant A's subdomain, replayed against
tenant B's. The smoke test script achieved this simply by handing the
*same* Guzzle `CookieJar` instance (populated by logging in on
`uesl.peopleos.test`) to a second `Client` configured with `base_uri`
set to `airpeace.peopleos.test` — no manual cookie extraction needed.
Guzzle's cookie jar automatically matches cookies by domain when
deciding what to send with a request, and `SESSION_DOMAIN=.peopleos.test`
(the same wildcard-subdomain cookie scope that made the original
Checkpoint 7 bug possible) means the session cookie is offered to
*any* `*.peopleos.test` request the jar is attached to — exactly
simulating a stolen/reused session cookie without needing to touch
Guzzle's internals. Both the web `/dashboard` page and
`GET /api/v1/dashboard` correctly returned `403` for this reused
session, confirmed live.

### Intentional test-behavior changes need a comment explaining "this used to pass differently, on purpose"

Three pre-existing tests exercised `/dashboard` with a bare
permission-less user and asserted success — true before this checkpoint
(no permission gate existed), false after (Checkpoint 21 adds
`dashboard.view`). Each updated test got a short comment stating this
explicitly (see `InertiaAuthTest::test_authenticated_user_can_access_dashboard`)
rather than a silent diff — a future reader diffing test history should
be able to tell "this assertion changed because the feature changed" from
"this assertion changed because of a mistake," and only a comment at the
change site makes that distinguishable later. Same pattern already
established in Checkpoint 16 when login/logout became content-negotiated.

## Settings: splitting "who holds this permission" from "which named role gets it" (Checkpoint 22)

Following the exact split established for the Dashboard (Checkpoint
21): `SettingsUiTest`/`TenantApiTest` verify the *permission-key*
behavior generically (throwaway roles built with `userWithPermissions()`,
the same helper every UI test file in this project already uses) —
does a `tenant.settings.view` holder reach `/settings`, does a
`tenant.view` holder see Company Profile, is a `documents.view`-less
user blocked. Which *named* seeded roles (Tenant Admin, HR Manager, HR
Officer, Auditor, Employee, Line Manager) actually get
`tenant.settings.view`/`audit.view` is a `RoleSeeder` mapping decision —
verified instead by a live smoke test against the real seeded/created
demo accounts, exactly like Checkpoint 21's Dashboard role-shaped
responses. Neither layer substitutes for the other: the automated tests
prove the *rule* is correct in isolation; the smoke test proves the
*role mapping* actually matches the intended per-role behavior your
plan specified. A checkpoint that only tested one of these could still
ship a role/permission mismatch undetected.

### A one-line permission-key fix in a pre-existing test, caught by the same first full-suite run every checkpoint relies on

`DashboardAndFrontendSecurityTest::placeholderRouteProvider()` still
listed `/settings` with `employees.update` (the Checkpoint 16 stand-in
permission this checkpoint replaced with the real `tenant.settings.view`).
Running the full suite immediately after implementing — not just the
new test files in isolation — caught this as a single failing test
(`test_all_placeholder_pages_are_backend_permission_gated` with data set
`"settings"`) rather than a silent gap. Fixed by updating the permission
key in that one data-provider row, with a comment explaining the
Checkpoint 22 change was the cause (matching the same "explain why this
assertion changed" discipline established in Checkpoint 21's own
pre-existing-test updates) — this is the same category of fix, not a
new pattern: any checkpoint that changes an existing route's permission
should expect (and go looking for) exactly this kind of stale
data-provider row elsewhere in the suite.

### Testing that a singleton endpoint can't be tricked into cross-tenant writes via body fields

`TenantApiTest::test_tenant_a_cannot_update_tenant_b_via_body_id` sends
a `tenant_id` pointing at a *real, different* tenant's ID alongside a
legitimate `name` change, then asserts **both** tenants' names
independently — tenant A's changed, tenant B's didn't. This is a
stronger check than simply asserting the response looks right: since
`GET`/`PATCH /api/v1/tenant` take no route parameter at all, there's no
`{tenant}` binding to attack the way `/employees/{employee}` tests do —
the only conceivable attack surface left is a client trying to smuggle
a different tenant's ID into the request *body*, so that's exactly what
this test tries and confirms fails silently (the field is simply
absent from `UpdateTenantRequest`'s rules, so it never reaches
`$tenant->fill()` at all).

## Users & Access: testing the primary defense, not a backstop (Checkpoint 23)

Every prior module's tenant-isolation tests were, strictly speaking,
testing a *second* layer — `BelongsToTenant`'s global scope was always
the first, and the explicit controller check was defense-in-depth on
top. `User`/`Role` have no such scope (see
`docs/security.md#users--access-management-ui`), so
`UserApiTest`/`RoleApiTest`'s tenant-isolation and platform-scope tests
(`test_tenant_a_cannot_view_tenant_b_users_via_list`,
`test_platform_admin_is_not_reachable_through_tenant_user_list_or_show`,
`test_platform_roles_are_not_reachable_through_tenant_role_list`, and
their Role equivalents) are proving the *only* line of defense holds,
not a redundant one. Worth remembering for any future model that also
skips `BelongsToTenant` for a deliberate reason (see `docs/architecture.md`
for why `User`/`Role` do): its tests need to be written with the
assumption that a missing filter is a real leak, not just a
theoretical one a scope would have caught anyway.

### A test-helper bug caught by the first full run, not by writing the test

`UserApiTest`'s `tenantAdminUser()` helper originally created a *new*
`Role::factory()` with slug `tenant-admin` on every call. The first two
tests that called it once each passed fine; the first test that called
it *twice for the same tenant*
(`test_can_deactivate_tenant_admin_when_another_admin_exists`, which
needs two separate Tenant-Admin-role holders) failed with a raw SQLite
unique-constraint violation on `(tenant_id, slug)` — the seeded-role
uniqueness rule doing exactly its job, just against a test fixture
rather than production data. Fixed by switching the helper to
`Role::query()->firstOrCreate(['tenant_id' => ..., 'slug' => 'tenant-admin'], [...])`,
so repeated calls for the same tenant share one role. A second,
unrelated bug surfaced in the same first run: the same helper granted
`users.deactivate` but not `users.view`, so a test asserting a Tenant
Admin could `GET` a user (which needs `users.view`) got a `403` instead
of `200` — fixed by granting both permissions the helper's name
implies a Tenant Admin would actually hold. Both were caught by running
the new test file once, immediately after writing it, rather than
trusting the code review alone — consistent with this project's
standing rule that every checkpoint runs the real test suite before
considering anything done.

### Testing "last Tenant Admin" from both directions, for both dangerous paths

Four dedicated tests exist, not two — one pair per dangerous path
(status update, role removal), each pair proving both the block *and*
its absence when safe:
`test_cannot_deactivate_last_active_tenant_admin`/
`test_can_deactivate_tenant_admin_when_another_admin_exists` for
status, `test_cannot_remove_last_tenant_admin_role`/
`test_can_remove_tenant_admin_role_when_another_admin_exists` for role
removal. Testing only the "blocked" half would leave a real risk
undetected: a rule implemented too broadly (e.g. accidentally blocking
*any* status change for *any* Tenant Admin, not just the last one)
would still pass a block-only test suite while silently breaking a
legitimate action every day. `test_cannot_deactivate_last_active_tenant_admin`
also deliberately uses a *different* actor than the target user (not
self-service) — proving Refinement 4's "applies even if the actor is
another admin, not just the user themself" holds, not just the
easier-to-reach self-deactivation case.

## Audit Log Viewing UI: a fixture side effect caught two count assertions off by one (Checkpoint 24)

`AuditLogApiTest`'s `userWithPermissions()` helper (the same pattern
used across every UI test file) calls `$user->assignRole($role)` to
grant the throwaway permission — which itself, correctly, writes a
`role.assigned` audit log entry (Checkpoint 4's `HasPermissions` trait
audits every role assignment, test fixtures included). Two tests that
assumed a freshly-created tenant would have exactly the audit logs the
test explicitly created
(`test_user_with_audit_view_can_list_audit_logs`,
`test_tenant_a_cannot_list_tenant_b_audit_logs`) failed with an
off-by-one count on the first run — not because tenant isolation was
broken, but because the fixture setup itself is not audit-log-neutral.
Fixed by reading the actual baseline count immediately after building
the test user, before creating the logs the test cares about, and
asserting against `$baselineCount + N` rather than a hardcoded `N`.
Worth remembering for any future test that counts audit log rows
against a tenant that already had *any* setup performed via a method
that itself triggers `AuditLogger` — role assignment, permission
grants, and (as of Checkpoint 22) tenant updates all do.

### Testing "structurally read-only" by inspecting the route table, not just by absence of a controller method

`test_no_audit_log_write_routes_exist` doesn't just check that
`AuditLogController` lacks `store()`/`update()`/`destroy()` methods —
it inspects Laravel's actual registered route list
(`Route::getRoutes()`) for any route whose URI starts with
`api/v1/audit-logs` and asserts none of them allow `POST`/`PUT`/
`PATCH`/`DELETE`. This is a stronger claim than "the controller I wrote
has no write methods" — it would also catch a future route
accidentally added pointing at a *different* controller, or a typo
that registered a write verb pointing at an existing read method.
Combined with `AuditLog::save()`/`delete()` throwing at the model layer
(Checkpoint 5), this checkpoint's "audit logs are read-only" claim is
backed by two independent, differently-shaped tests rather than one.

## Document Categories & Leave Types: proving a Resource change is safe *before* making it, not after (Checkpoint 25)

Removing `created_by`/`updated_by` from `DocumentCategoryResource`/
`LeaveTypeResource` touched two Resources that had existed since
Checkpoints 9 and 12 — a real risk of breaking something nobody
remembered depended on them. Rather than remove the fields and see what
broke, the check ran the other way: grep the entire test suite first
for any assertion on these two field names in either module's response
shape, confirm none exist (the one `created_by` hit,
`LeaveTypeApiTest::test_user_with_permission_can_create_leave_type`,
turned out to check `assertDatabaseHas()` — the database row, not the
API response), *then* make the change, *then* re-run both modules'
existing suites to confirm nothing broke. This ordering — verify safety
before changing, not just after — is worth repeating any time a
checkpoint touches a Resource or model that predates it by several
checkpoints, since "grep first" is cheap and "revert after a surprise
failure" isn't.

### Testing a deliberate exception to an established convention, not just the convention itself

`test_max_days_per_year_can_be_cleared_to_null` doesn't test the
general "optional fields can be updated" behavior every other Leave
Type field already gets implicit coverage for via
`LeaveTypeApiTest`'s existing update tests — it specifically tests the
one field that behaves *differently* from every other optional field
in this app's forms (explicit `null` vs. omit-if-blank). Seeds a leave
type with a real numeric cap (`21`), sends an explicit `null`, and
asserts both the JSON response *and* the database row reflect the
clear — a test that only checked the response could still pass if the
value silently failed to persist. Worth writing this kind of test
whenever a checkpoint deliberately breaks its own established pattern
for one specific field — the exception is exactly the part regression
would most easily reintroduce by "fixing" it back to the general rule.

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

### Live-testing an Inertia page load: the page data lives in a `<script>` tag, not a `data-page` div attribute (Checkpoint 16)

Older Inertia examples/tutorials show the initial page object embedded
as a `data-page="..."` attribute on the root `<div id="app">`. This
project's Inertia version instead embeds it as JSON text inside
`<script data-page="app" type="application/json">{...}</script>`. A
smoke-test script parsing the raw HTML response needs to extract from
the script tag's *text content*, not an attribute value:

```php
preg_match('/<script data-page="app" type="application\/json">(.+?)<\/script>/s', $html, $m);
$page = json_decode($m[1], true); // {component, props, url, version, sharedProps}
```

Also worth knowing: a **plain** `GET` (no `X-Inertia` header) always
gets this full HTML document — the natural shape of a direct URL visit
or full page reload. An `X-Inertia: true` GET gets a raw JSON page
object directly, *but* only if it also sends a matching
`X-Inertia-Version` header — omit it (as a hand-rolled smoke-test script
easily might) and Inertia's version-mismatch handling returns `409`,
which looks like a bug but isn't: it's Inertia correctly telling a
client with a stale/unknown asset version to do a full reload. For a
one-off smoke test that doesn't already have a previous page's version
to carry forward, skip the `X-Inertia` header on `GET`s entirely and
parse the embedded script tag instead — `X-Inertia`/`X-Requested-With`
headers are still useful (and safe) on `POST`s (login/logout), since
Inertia's version check only applies to `GET`.

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
