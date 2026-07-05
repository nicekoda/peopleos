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

## User ↔ Employee Linking

Closes the identity gap flagged in Policy Management (previous
checkpoint): `employees.user_id` (nullable, unique, FK → `users.id`
`SET NULL`) is the single link between an authentication account and an
HR employee record — see [`database.md`](database.md#employees) for the
column reference and [`security.md`](security.md#user--employee-linking)
for the full linking/permission design.

**One column, one unique constraint, both directions covered.** A nullable
*unique* FK on the "many" side of what is really a 1:1 relationship means
Postgres itself rejects a second employee claiming a `user_id` already in
use, and the app-layer validation additionally rejects linking a user who
already owns a different employee — both directions checked, but only one
constraint needed at the schema level.

**Linking is a distinct, permission-gated action** (`employees.link_user`
/ `employees.unlink_user`), not a field on the general employee update
endpoint — deliberately kept off `UpdateEmployeeRequest`, the same
reasoning already applied to `created_by`/`updated_by` in the previous
checkpoint's `$fillable` fix: a column can be mass-assignable for one
trusted, narrow controller action without being reachable through a
broader endpoint's request input.

**`GET /api/v1/me/employee` is the first genuinely self-scoped endpoint**
in the app — no route parameter, no permission middleware, because "am I
allowed to see my own linked employee record" isn't a permission question
at all, it's inherent to being authenticated. Resolves entirely from
`$request->user()->employee` (a new `User::employee(): HasOne`).

**This is what finally makes safe self-service possible.**
`PolicyController::acknowledge()` now resolves the target employee from
the caller's own verified link by default (`acknowledgement_method: web`)
and only allows acting on someone else's behalf if the caller separately
holds `policies.assign` (`acknowledgement_method: admin_recorded`) — see
[`security.md`](security.md#the-acknowledgement-redesign-two-paths-one-endpoint)
for the full reasoning. This is why `policies.acknowledge` can now be
granted to the Employee role, which was explicitly withheld from it in
the previous checkpoint for exactly this reason.

### Required `$fillable` quality review — one real bug found

Per your instruction, every model's `$fillable` array was reviewed against
its controllers' actual `create()`/`update()` calls before this checkpoint
added more fields to the pattern. Nine models checked: `Employee`, `User`,
`Department`, `Location`, `Position`, `DocumentCategory`,
`EmployeeDocument`, `Policy`, `PolicyVersion`. One real bug found:
`User`'s `#[Fillable(...)]` attribute was missing `email_verified_at` —
confirmed via an isolated `User::create([..., 'email_verified_at' =>
now()])` reproduction, which persisted `NULL`. No controller in the app
currently sets this field via `create()`/`update()` (no
email-verification or admin-creates-user flow exists yet), so this was a
latent gap, not an active data-loss bug like the `created_by`/`updated_by`
one found last checkpoint — but the exact same bug *class*: a column
excluded from `$fillable` silently drops any future trusted assignment,
whether or not something happens to call it yet. Fixed by adding it to
the attribute. The other eight models' `$fillable` arrays were confirmed
correct against their controllers' current usage.

### CLI/tinker gotcha, not a production bug

`tenant_id` is deliberately excluded from every tenant-owned model's
`$fillable` (it's auto-filled by `BelongsToTenant` from the
container-bound `Tenant`, never accepted as request input — see
`architecture.md`'s Multi-Tenancy section). This means `Employee::create([
'tenant_id' => $t->id, ...])` from `tinker` or a one-off CLI script
**silently drops** `tenant_id` too, the same mass-assignment behavior as
everywhere else, and fails on the table's `NOT NULL` constraint. Outside
a real HTTP request, nothing binds `Tenant::class` into the container, so
there's no automatic fill to fall back on either. Not a bug — real
requests always go through `ResolveTenant` first — but worth knowing
before reaching for `tinker` to seed one-off tenant-owned records: set
`$model->tenant_id` directly (bypassing mass assignment), then `fill()`
the rest.

## Leave Management

The first real tenant-owned **workflow** module — every prior business
module (Employee Records, Documents, Policies) was CRUD-plus-lifecycle;
this one has a genuine multi-actor state machine (`draft → pending →
approved/rejected/cancelled`) with different actors trusted for
different transitions. See [`api.md`](api.md) and
[`security.md`](security.md#leave-management) for the full design.

**Built directly on the User ↔ Employee Linking foundation.** Leave
request creation is self-service *only* because `$request->user()->
employee` (Checkpoint 11) exists to resolve "which employee is this" —
without it, this checkpoint would have faced the exact same "no
verified identity link" problem Policy Management hit in Checkpoint 10,
and would have had to make the same "admin-recorded only" compromise.
Instead, self-service was safe to build from day one here.

**Status transitions are centralized, not re-implemented per action.**
`App\Enums\LeaveRequestStatus::canTransitionTo()` is the single source
of truth for which transitions are legal; every write action
(`submit`/`approve`/`reject`/`cancel`) routes through
`LeaveRequestController::ensureTransitionAllowed()` rather than each
action independently checking "is the current status X." This is
deliberate — a workflow with 5 states and asymmetric actor trust
(employee-only vs. HR-only transitions) is exactly the kind of code
where re-implementing the same check five slightly-differently-worded
times would eventually drift.

**Two distinct kinds of object-level check, given different HTTP status
codes on purpose.** `LeaveRequestController` distinguishes:

- **Visibility** (`show`/`index`) — does the caller have *any*
  legitimate path to know this resource exists? Own request, or
  `leave.view_all`. Failure → `404`, the same "don't reveal existence"
  posture used everywhere else in this app.
- **Self-service action ownership** (`update`/`submit`/`cancel`) — is
  the caller specifically *this* request's owner? An HR user with
  `leave.view_all` can already see the resource (so hiding it via `404`
  would be misleading, not a real IDOR protection) but still isn't
  allowed to submit/edit/cancel someone else's draft. Failure → `403`.

Worth remembering as a general pattern: "can this caller know this
resource exists" and "can this caller perform this specific action on
it" are different questions, and conflating their status codes either
over-reveals (403 when 404 was warranted) or under-informs (404 to
someone who legitimately already knows the resource is there).

**A permission granted broadly was deliberately withheld from one role
this checkpoint, for the same reason as Checkpoint 10's Employee/
`policies.acknowledge` decision.** Line Manager's suggested mapping
included `leave.approve`/`leave.reject`, but this checkpoint has no
manager-hierarchy enforcement (nothing checks "is this approver actually
this employee's manager") — granting it now would let any Line Manager
approve any employee's leave tenant-wide. Left as an empty placeholder,
same as 15 other roles already are, until manager-hierarchy-scoped
approval is built. This is the same shape of decision as Checkpoint 10's,
now the second time this exact pattern ("a suggested grant would create
an unscoped blast radius without a feature that doesn't exist yet") has
come up — worth watching for a third time as a signal that "permission
implies role" mappings in future spec documents should be treated as
starting points, not given.

## Manager Hierarchy

Reuses `employees.manager_employee_id` (present since Checkpoint 6, no
schema change this checkpoint) but adds everything that was missing
around it: cycle prevention, active/tenant/soft-delete validation, a
dedicated write path, and a reusable query service. See
[`api.md`](api.md#manager-hierarchy) and
[`security.md`](security.md#manager-hierarchy) for the full design.

**`App\Services\ManagerHierarchyService` is the single place "who
manages whom" logic lives**, deliberately factored out of any one
controller so future modules (leave approval scoping, performance/
probation reviews, onboarding tasks, team dashboards, org chart — all
explicitly named as this checkpoint's rationale) can reuse
`isManagerOf()`/`directReportsOf()` rather than each re-deriving the
chain walk independently. `Employee::manages()`/`directlyManages()` are
thin convenience wrappers over the service, not a second
implementation.

**A write path is closed off structurally, the same pattern used
repeatedly since Checkpoint 11.** `manager_employee_id` is no longer a
validated field on `StoreEmployeeRequest` or `UpdateEmployeeRequest` —
removed entirely, not just left with weaker validation. Every manager
assignment/removal goes through `PATCH`/`DELETE
/employees/{employee}/manager`, the only code path that runs the full
check (tenant match, active status, soft-delete exclusion, cycle
detection). This mirrors exactly how `employees.user_id` was closed off
from the general update endpoint in Checkpoint 11 — a recurring,
now-established pattern: *when an existing field needs materially
stronger validation than a general CRUD endpoint can reasonably carry,
remove it from that endpoint rather than trying to bolt the stronger
check on in place.*

**Fail-closed cycle detection, not just cycle-when-detected.**
`ManagerHierarchyService::wouldCreateCycle()` doesn't only return `true`
for an actual cycle — it also returns `true` (block the assignment) if
the chain above the prospective manager turns out to be untrustworthy
for any reason: a repeated employee ID (already cyclic), a chain deeper
than a safety cap, a cross-tenant link, a soft-deleted employee, or a
non-`active` employee anywhere in the chain. The walk deliberately uses
`Employee::withoutGlobalScopes()` so a cross-tenant or soft-deleted
employee in the chain is actually *seen and rejected*, rather than
silently vanishing from a normally-scoped query and truncating the walk
early into a false "no cycle found." See
[`security.md`](security.md#fail-closed-cycle-detection-refinement-2)
for the full reasoning.

**Two different depth caps, for two different reasons — not the same
constant reused.** `ManagerHierarchyService::MAX_CHAIN_WALK` (100) is a
corruption/infinite-loop safety net for the *write-path* cycle check —
a real org should never get anywhere near it. `EmployeeHierarchyController::
DEFAULT_REPORTING_TREE_DEPTH` (5) is a *display*-endpoint response-size
cap for `reporting-tree` — a real org can legitimately be deeper than 5
levels, and hitting the cap just means the response reports
`reports_truncated: true` rather than fetching without limit. Conflating
these would have been wrong in both directions: a corruption-detection
threshold that low would reject legitimate deep orgs, and a display cap
that high would make the tree endpoint's response size effectively
unbounded.

**A third instance of the same "unscoped blast radius" pattern flagged
in Checkpoints 10 and 12.** This checkpoint deliberately does *not*
grant Line Manager `leave.approve`/`leave.reject` — those still require
`LeaveRequestController`'s approve/reject actions to be scoped by
`ManagerHierarchyService::isManagerOf()`, which is a **future**
checkpoint's work, not this one's. Line Manager receives
`employees.view_team` only. See
[`security.md`](security.md#manager-hierarchy) for the full reasoning,
and the note in `RoleSeeder` — worth treating this recurring shape
("a suggested permission grant would create tenant-wide reach without
the scoping feature that makes it safe") as a standing checklist item
for every future role-mapping decision, not a one-off exception.

## Manager-Hierarchy-Scoped Leave Approval

Closes the loop Checkpoint 13 explicitly set up: `LeaveRequestController::
approve()`/`reject()` now call `ManagerHierarchyService::directlyManages()`
to determine whether a caller may act on a specific request, rather than
tenant-wide reach for anyone holding `leave.approve`/`leave.reject`. See
[`security.md`](security.md#manager-hierarchy-scoped-leave-approval) for
the full design.

**A permission's presence stopped being sufficient on its own — this is
the notable shift.** Every prior checkpoint's authorization model was
"does the caller hold permission X" (checked once, by route middleware).
This checkpoint introduces the first case in the app where holding the
route-gating permission (`leave.approve`) is *necessary but not
sufficient* — the controller must additionally resolve *which* scope
justifies the specific action (`hr_admin` via `leave.view_all`, or
`direct_manager` via a verified relationship), and reject if neither
applies. `resolveApprovalScope()` returning `null` is a distinct outcome
from "permission missing" (which route middleware already handles) —
it's "permission present, but this specific resource isn't within your
authorized scope." Worth recognizing this shape for any future module
where a single permission needs to mean different things depending on
*who* holds it (HR vs. line manager is unlikely to be the last such
case — the same shape would apply to, say, a future "approve expense
reports" permission split between finance and direct managers).

**Direct reports only, by explicit design decision — not a limitation
to be worked around quietly.** `directlyManages()` (not `isManagerOf()`,
which walks the full chain) is used deliberately. A grandparent manager
cannot approve a grandchild's leave this checkpoint, even though
`ManagerHierarchyService` is technically capable of answering that
question via `isManagerOf()`. This is a policy choice, not a technical
gap — see `security.md` for the reasoning, and don't "fix" this by
swapping in `isManagerOf()` without a deliberate decision to broaden
scope, since that changes who can act on whose data.

**`leave.view_team` is a new, third visibility tier — not a
reinterpretation of an existing permission.** `leave.view` (self only),
`leave.view_team` (self + direct reports), `leave.view_all` (tenant-
wide) are three genuinely different scopes with three different
permission keys, deliberately not collapsed into fewer flags with
conditional meaning. This mirrors the `employees.view_team`/
`employees.update_manager` split from Checkpoint 13 and the
`policies.acknowledge`/`policies.assign` split from Checkpoint 11 — the
recurring pattern in this app is: when an action needs a genuinely
different authorization scope, introduce a new permission key rather
than overload an existing one with context-dependent meaning.

## Leave Balances Foundation

Adds `leave_balances` (per employee/leave-type/year) and wires
enforcement into the existing `LeaveRequestController::submit()`/
`approve()`/`reject()`/`cancel()` actions — no new leave-request
endpoints, this is a constraint layered onto the existing workflow. See
[`security.md`](security.md#leave-balances-foundation) and
[`api.md`](api.md#leave-balances) for the full design.

**`available_days` is computed, never stored** — `entitlement_days +
carried_forward_days + adjustment_days - used_days - pending_days`,
evaluated fresh on every read (`LeaveBalance::availableDays()`). This is
the same principle already applied to `LeaveRequest::total_days` never
trusting client input, extended to "don't even trust your own
denormalized cache of a value that's cheap to recompute."

**Balance-controlled is opt-in per leave type, not a global switch.** A
leave type with `max_days_per_year = null` has no balance row ever
created for it and no enforcement at all — `LeaveBalanceService::
isBalanceControlled()` is the single gate every workflow action checks
before touching balance logic at all. This means the feature can be
adopted leave-type-by-leave-type without a data migration for existing
unlimited types.

**The transaction boundary spans the balance mutation *and* the leave
request's own status change, deliberately, not two separate
transactions.** `DB::transaction()` wraps both in `submit()`/`approve()`/
`reject()`/`cancel()` — a balance check/reservation failure aborts
before the leave request's status ever changes; a status-update failure
after a successful reservation rolls the reservation back too. This is
the first place in the app where two different tables' writes needed to
be atomic with each other, not just internally consistent.

**Locking, not optimistic retry.** `LeaveBalanceService::findOrCreate()`
takes a `lockForUpdate()` row lock on the balance before any read used
for a decision (the `available_days >= requested` check). Two concurrent
submits against the same balance serialize at the database level rather
than racing to read a stale value — the classic "check-then-act" bug
this pattern exists to close. The one unavoidable race (two *first-ever*
submits for the same employee/leave-type/year, before any row exists to
lock) is handled by catching the partial unique index's constraint
violation and re-fetching (now lockable) instead of failing outright —
see `LeaveBalanceService::findOrCreate()`.

**`pending_days` is a shared aggregate per balance, not a per-request
ledger** — this is why `cancel()` must know whether the specific leave
request it's cancelling was actually `Pending` (i.e. had itself
contributed to that aggregate via `submit()`) before calling
`releasePending()`. Cancelling a `Draft` request must be a no-op on
balance, because a draft never reserved anything — releasing anyway
would silently steal reserved balance from a *different* pending
request against the same balance row. Found and fixed during this
checkpoint's own implementation, not by a later bug report — worth
remembering as a general shape: any aggregate counter fed by multiple
independent writers needs each release/consume call to verify it's
undoing *its own* prior contribution, not just "the same field."

**A cross-tenant test-fixture bug found while implementing this
checkpoint, unrelated to leave balances specifically.** `LeaveRequestFactory`'s
`leave_type_id => LeaveType::factory()` default creates a brand-new,
randomly-tenanted `LeaveType` unless told otherwise — harmless as long
as nothing ever dereferences `$leaveRequest->leaveType`. This
checkpoint is the first code to actually load that relation from a
real tenant-scoped request (to check `max_days_per_year`), and
`BelongsToTenant`'s global scope silently filtered it to `null` for
every existing test that overrode `tenant_id` without also pinning
`leave_type_id` to the same tenant — 15 tests broke, not because of new
behavior being wrong, but because the relation had never been
meaningfully exercised in a tenant-scoped context before. Fixed via
Laravel's `Factory::recycle()` at each affected call site rather than
patching every test's fields individually — see `docs/testing.md` for
the full explanation and why `recycle()` is the right tool for this
class of problem going forward.

## Frontend Foundation (Inertia + React + TypeScript)

The first frontend this app has ever had — every prior checkpoint was
API-only. See [`security.md`](security.md#frontend-security-model) for
the full "what's shared, what never is, why the frontend is never the
security boundary" design, and [`api.md`](api.md#frontend-routes-inertia)
for the route/page reference.

**One endpoint, content-negotiated, not two parallel auth systems.**
`AuthenticatedSessionController::store()`/`destroy()` branch on
`$request->expectsJson()` — a caller that actually wants JSON
(`postJson()` in every existing test, or a genuine API client) gets
exactly the same response as before this checkpoint; a real
browser/Inertia form post (which doesn't set that header) gets a
redirect instead. This is the standard Laravel+Inertia hybrid pattern,
not a custom invention — and it's why `AuthenticationTest.php`
(Checkpoint 3) needed no logic changes, only its own test calls
switched from bare `post()` to explicit `postJson()` once the same URL
started serving two audiences with genuinely different expectations.
See `docs/testing.md` for the two pre-existing test files this exposed
(`AuthenticationTest`, `AuditLoggingTest`, and `TenantMatchingMiddlewareTest`)
that had silently relied on "every response from this endpoint is JSON,
regardless of what I asked for" — true only because no alternative had
ever existed until now.

**`HandleInertiaRequests::share()` is the single place shared frontend
props are assembled** — one function, not scattered across controllers.
Mirrors the `AuditLogger`/`ManagerHierarchyService` pattern already
established in this app: when a concern needs to be correct
consistently everywhere, give it exactly one implementation, not N
callers each getting to decide independently what's "safe enough" to
expose.

**Page routes are backend-permission-gated, not just hidden from the
nav.** Every placeholder route (`/employees`, `/leave`, `/documents`,
`/policies`, `/settings`) carries the same `permission:{key}` middleware
its real API endpoints already require. `PermissionGate`/`useCan()`
(React) only ever decide what to *render*; a direct link, a bookmarked
URL, or a modified request to any of these routes is rejected by the
backend regardless of what the sidebar shows. This is the same
"backend remains authoritative" principle already applied to every
other permission check in this app — extended to page routes, not a
new principle invented for the frontend.

**Nav only lists modules with an actual page this checkpoint.**
"Manager," "Reports," and "Audit" were suggested nav groups but have no
page yet — deliberately left off the sidebar rather than linking
somewhere that 404s. Add them to `Sidebar.tsx`'s `links` array once
their pages exist, not before.

**Page resolution is lazy, one chunk per page (Checkpoint 26).**
`resources/js/app.tsx`'s `createInertiaApp({ resolve })` originally used
`import.meta.glob('./Pages/**/*.tsx', { eager: true })` — every single
page component, across every module, eagerly bundled into one main JS
chunk regardless of which page was actually requested. That eager glob
was the direct cause of the >500kB build-size advisory reported at the
end of Checkpoint 25, not anything inherent to the app's size. Switched
to `laravel-vite-plugin/inertia-helpers`'s `resolvePageComponent()` with
a lazy glob (no `eager: true`), the standard Inertia+Vite pattern: each
page becomes its own chunk, fetched only when its route is actually
visited. Result: the main chunk dropped from 500.41 kB to 321.57 kB
(gzip 137.67 kB → 101.23 kB), and Vite's "chunk larger than 500 kB"
warning is gone. No custom code-splitting logic, no route-level
`React.lazy()` calls scattered through page code — one change, in one
file. See `docs/testing.md` for how this was verified (build output +
`tsc --noEmit` + the full live smoke test, since async component
resolution is a real runtime behavior change, not just a build-config
tweak).

## Employee Records UI (Checkpoint 17)

The first real module screen — `/employees`, `/employees/create`,
`/employees/{id}`, `/employees/{id}/edit`. See
[`security.md`](security.md#employee-records-ui) and
[`api.md`](api.md#frontend-routes-inertia) for the security model and
route reference.

**Client-side data fetching, not server-rendered props — a deliberate
architecture decision, not a default.** `EmployeeUiController`'s four
methods each do nothing but `Inertia::render('Employees/...')`; `show()`/
`edit()` pass only `employeeId` (a route-model-bound, already tenant-
scoped string). The actual employee record is fetched by the React page
component itself, via `resources/js/lib/api.ts`, hitting the exact same
`/api/v1/employees` endpoints already built and tested in Checkpoints
6/7/11/13. This was the right call here specifically because a fully-
built, independently-tested JSON API already existed — reusing it
directly avoids duplicating data-loading/masking logic into a second
(web-controller) code path that could drift from the API's own
behavior. Future module UIs (Leave, Documents, Policies) should default
to this same pattern: thin web routes, `lib/api.ts` for data, unless a
specific reason argues otherwise.

**`lib/api.ts` is the second "single place a concern lives" pattern
introduced on the frontend** (the first was `HandleInertiaRequests::share()`
in Checkpoint 16) — one axios instance, one `toApiError()` normalizer,
reused by every page that talks to the API, rather than each page
rolling its own fetch/error-handling logic. Mirrors `AuditLogger`/
`ManagerHierarchyService`/`LeaveBalanceService` on the backend: when
something needs to behave consistently everywhere, give it exactly one
implementation.

**Form payloads are built from an explicit allowlist type
(`EmployeeFormPayload`), never by spreading the fetched `Employee`
object.** This is a second layer behind the backend's own field
exclusions (`Store`/`UpdateEmployeeRequest` already reject `tenant_id`/
`manager_employee_id`/user-link fields structurally) — belt and braces,
not a replacement for the backend check. Confirmed directly during the
live smoke test: a payload that deliberately included `tenant_id` and
`manager_employee_id` was still accepted (`201`), with both fields
silently ignored and `manager_employee_id` staying `null` — the
backend, not the frontend's honest form, is what actually enforced this.

**`department_id`/`location_id`/`position_id` are omitted from every
form and display, not just the create form** — there's no listing
endpoint for departments/locations/positions yet (unchanged limitation
since Checkpoint 6), so there's no safe way to let a user pick a real
value. A future checkpoint adding that listing API is a prerequisite
for surfacing these fields in the UI at all.

## Leave Management UI (Checkpoint 18)

The second real module screen, following the exact same architecture
Checkpoint 17 established — see
[`security.md`](security.md#leave-management-ui) for the security model
and [`api.md`](api.md#frontend-routes-inertia) for the route reference.

**`LeaveUiController` is a near-copy of `EmployeeUiController`** — three
thin methods, `show()` passing only `leaveRequestId`. This consistency
is deliberate: a developer who understands one module's web-controller
shape already understands the other's. Any future module UI should
follow the same three-method shape unless there's a specific reason not
to.

**Three independent client-side fetches on `/leave`, not one
combined endpoint** — leave requests (`/leave-requests`), leave types
(`/leave-types`, for the name lookup), and the viewer's own balances
(`/me/leave-balances`) are fetched separately, each with its own
loading/error state. A failure in one (e.g. the balances call) doesn't
block the other two from rendering. This is a deliberate tradeoff
(three round trips instead of one) in favor of resilience and reusing
existing single-purpose endpoints rather than inventing an aggregate
"leave dashboard" endpoint that don't exist in the API — matches "do
not build... advanced dashboard" from your scope.

**Two identifier-display problems solved the same way, one new
technique.** Checkpoint 17 solved "no name lookup available at all" by
omitting the field entirely (department/location/position). Checkpoint
18 hits a *different* shape of the same problem: `LeaveRequestResource`
returns `employee_id` (a real, useful identifier for HR/Manager views —
unlike department IDs, it's the only way to tell rows apart in a multi-
employee list) but no employee *name*. Omitting it entirely would make
a tenant-wide/team leave list unusable; showing the raw ULID
prominently would look like an unfinished design. `resources/js/lib/format.ts`'s
`formatEmployeeRef()` threads this: "You" for the viewer's own request
(comparing against the already-shared `auth.user.employee_id`), a
visibly provisional, truncated placeholder otherwise
(`Employee record (ID ending •••1234)`). Future work: a real employee
name/summary field on the leave API, at which point this function goes
away entirely rather than needing a redesign — it was written to be
disposable.

**The frontend cannot know `ManagerHierarchyService`'s scope, and
doesn't pretend to.** Approve/Reject render whenever the viewer holds
`leave.approve`/`leave.reject` and the request is `pending` — full
stop, no attempt to predict whether the backend will actually accept a
specific request based on manager-hierarchy scope. A `403` from a
button that *looked* available is treated as a completely normal,
expected outcome (same generic safe message as any other `403`), not a
bug to route around. Confirmed live: a Line Manager successfully
approved their direct report's request and was correctly `403`'d
approving an unrelated employee's — from the UI's perspective, both
buttons were equally "available."

## Document Repository UI (Checkpoint 19)

The third real module screen, and the first one deliberately **not**
built as a top-level module — see
[`security.md`](security.md#document-repository-ui) for the security
model and [`api.md`](api.md#frontend-routes-inertia) for the route
reference.

**Employee-scoped, not tenant-wide, because the backend API is
employee-scoped.** Every existing document endpoint
(`EmployeeDocumentController`, Checkpoint 8) is nested under
`/api/v1/employees/{employee}/documents/...` — there is no tenant-wide
`/api/v1/documents` listing endpoint to build a document centre on top
of. Rather than inventing one prematurely, the UI mirrors the API's own
shape: `/employees/{employee}/documents(/upload)(/{document})`, reachable
from a "Documents" link on the Employee detail page. A tenant-wide
document centre is explicit future work, gated on a tenant-wide listing
endpoint actually existing first (see "Future" below).

**`EmployeeDocumentUiController` mirrors `EmployeeUiController`/
`LeaveUiController`'s three-method shape, with one addition**: `show()`
takes *two* route-bound models, not one, and does *two* object-level
ownership checks accordingly — `ensureEmployeeBelongsToCurrentTenant()`
(same as every other module) *and* `ensureDocumentBelongsToEmployee()`,
because `/employees/{employee}/documents/{document}` has a nesting
relationship the single-model Employee/Leave routes don't: a `document`
ID that's perfectly valid *for the current tenant* but belongs to a
*different employee* must still 404, not just IDs from a different
tenant. This is the same two-layer check `EmployeeDocumentController`
already does at the API layer (Checkpoint 8) — the web controller
repeats it rather than trusting the API layer alone, consistent with
every other module's "don't rely solely on one layer" posture.

**A pre-existing permission gap, closed narrowly.** `GET
/api/v1/document-categories` requires `document_categories.view` — but
before this checkpoint, only Tenant Admin held it. HR Manager and
Employee (the only two roles holding `documents.upload`) would have hit
a `403` fetching the category list the upload form depends on for
sensitivity/expiry-requirement display. Fixed by granting
`document_categories.view` — and *only* that, not `create`/`update`/
`delete` — to both roles in `RoleSeeder`. Viewing what categories exist
is reference data needed to upload correctly; managing the category
catalog remains a materially higher-trust action reserved for Tenant
Admin. See [`security.md`](security.md#document-repository-ui) for the
full reasoning.

**`lib/download.ts` — a new helper, not a reuse of `lib/api.ts` alone.**
Every other module's data flows as JSON through `api.get()`/`.post()`;
a file download is fundamentally different (a binary response, not
something to parse and render) and has a failure mode the JSON helpers
don't: with `responseType: 'blob'`, a failed request's error body
arrives as a `Blob`, not parsed JSON — `toApiError()` can't read
`.message`/`.errors` off a `Blob` directly. `downloadEmployeeDocument()`
re-parses a failed blob response as text/JSON before handing it to
`toApiError()`, so a `403`/`404` still produces the same safe generic
message every other API call gets, rather than either a blank message
or (worse) the raw error blob being silently offered to the browser as
if it were the requested file. See `docs/security.md` for why this
also rules out a plain `window.location = downloadUrl` navigation.

## Policy Management UI (Checkpoint 20)

The fourth real module screen, and the first that required a small,
approved backend addition rather than working entirely within the
existing API surface — see
[`security.md`](security.md#policy-management-ui) for the security model
and [`api.md`](api.md#policy-management) for the route reference.

**The missing-versions-list gap, and why it was a real blocker, not a
nicety.** `PolicyResource` exposes only `current_version_id` — a bare
ID, no title/summary/content behind it — and before this checkpoint the
only version-related endpoint was `POST .../versions` (create). Two of
the required goals were structurally impossible without more: showing
"current version content" on the detail page (nothing to fetch it with),
and letting the user pick which draft to publish (`PublishPolicyRequest`
requires a specific `policy_version_id`, and there was no way to
discover one except remembering it from the moment a version was just
created in the same browser session — a normal "create a draft today,
publish next week" workflow would have had no way to find it again).
Flagged and approved before implementation, per your standing "stop and
flag backend gaps" instruction — see the approved plan in the checkpoint
transcript.

**The fix stayed deliberately narrow**: one new controller method,
`PolicyController::versions()`, one new route, no new permission (reuses
`policies.view` — the same trust level as viewing the policy itself),
no new write path. Scoped through `$policy->versions()->orderByDesc('version_number')->paginate()`,
never a free query filtered by a request-supplied `policy_id` — this is
what makes "a version from a different policy in the same tenant can
never leak into this policy's list" a property of the query itself,
not something a controller-level check has to remember to enforce
separately (tested directly:
`PolicyApiTest::test_versions_endpoint_only_returns_versions_for_the_requested_policy`).

**Two flows that only make sense once a version exists, handled as
"nothing to do" rather than broken UI.** The Publish control on
`Policies/Show.tsx` fetches the versions list and filters to
`status: draft`; with zero drafts, it renders "No draft versions
available to publish" instead of a dropdown with nothing in it or (worse)
a button that would submit a guessed/empty version ID. The Assign page
checks `policy.current_version_id` before rendering its form at all —
a policy with no published version shows "Publish a version first to
enable assignment" instead of a form that would just 422 on submit. Both
mirror `AssignPolicyRequest`'s own `current_version_id` requirement and
`PublishPolicyRequest`'s draft-only `policy_version_id` scoping — UI
conveniences layered on top of rules the backend enforces regardless.

**Acknowledgement stays deliberately one-directional.** The Acknowledge
button on the detail page calls `POST /policies/{policy}/acknowledge`
with an empty body — never `employee_id`. This is not an oversight;
building an "acknowledge on behalf of someone else" UI was explicitly
out of scope this checkpoint, so the frontend only ever exercises
`PolicyController::acknowledge()`'s self-acknowledgement path (resolved
from the caller's own linked employee, Checkpoint 11). The
admin-recorded-on-behalf-of path still exists at the API layer and
remains fully tested (`PolicyApiTest`), just with no UI entry point yet.

**Policy version content renders as plain, escaped text — no rich text
editor, no `dangerouslySetInnerHTML`.** `content` is a free-text field
(`PolicyVersion.content`); rendering it via JSX text interpolation
(`{content}`) is inherently safe (React escapes text children), and
deliberately not upgraded to a rich-text/HTML editor or renderer this
checkpoint, per your explicit "simple and safe, no rich text editor"
instruction.

**`owner_user_id` and `employee_document_id` are both accepted by the
backend but omitted from every form.** `owner_user_id` is validated
safely server-side (a tenant-scoped `Rule::exists('users', ...)`), but
there is no `/api/v1/users` listing endpoint at all — no safe lookup UI
could be built without inventing one, so the field is simply never
offered. `employee_document_id` has existed on `policy_versions` since
Checkpoint 10 specifically as a known semantic mismatch (an
employee-owned document is a poor fit for a tenant-wide policy
document, see `docs/security.md`'s Policy Management section) — no
general/policy-scoped document picker exists yet, so version creation
stays content-only. Both are documented future work, not silent gaps.

## Dashboard Foundation (Checkpoint 21)

The first checkpoint to aggregate data *across* modules rather than
building a new module — see
[`security.md`](security.md#dashboard-foundation) for the permission
model and [`api.md`](api.md#dashboard) for the response shape.

**A summary endpoint, not a listing endpoint — this distinction is the
whole design.** `GET /api/v1/dashboard` returns only aggregates (counts,
a sum, a handful of already-safe labels) computed server-side; it never
returns raw records the way `/employees` or `/leave-requests` do. This
matters for the security model: every value is derived from a query the
backend already decided was safe to run for this specific user, not a
generic list the frontend then filters — there's no client-side
filtering step that could be bypassed, because there's no raw data to
filter in the first place.

**`dashboard.view` is an access permission, not a data permission.** It
gates whether `/dashboard`/`/api/v1/dashboard` can be reached at all —
nothing more. Every card inside the response is independently gated by
the same module permission its real page would require
(`employees.view` for employee counts, `leave.view` for the leave
summary, and so on) — holding `dashboard.view` without any module
permissions produces a `200` with an empty `cards`/`recent_items` array,
not an error and not a data leak. This two-layer gate (reach the
endpoint, then earn each card) is a new shape for this app — every
prior module used a single permission tier per page — and is the direct
implementation of your explicit "`dashboard.view` alone must not grant
access to module data" rule.

**`LeaveVisibilityService` — an extraction, not a new design.** The
dashboard's leave card needs the exact same "which employee_ids can
this user see" answer `LeaveRequestController::index()` already computes
(tenant-wide via `leave.view_all`, direct-reports via `leave.view_team`,
or just the caller's own). Duplicating that logic into the dashboard
controller would have created a second place for the Checkpoint 14
manager-scope rule to silently drift out of sync. Instead, the existing
private `visibleEmployeeIds()` method was extracted verbatim into
`App\Services\LeaveVisibilityService`, and `LeaveRequestController` now
calls the same service — a pure refactor, confirmed behavior-identical
by re-running the full pre-existing Leave test suite (123 tests)
unchanged after the extraction.

**Document cards stay self-scoped because the permission model doesn't
yet support anything else safely.** Leave has `leave.view` vs.
`leave.view_all` vs. `leave.view_team` — three distinct trust tiers.
Documents have only `documents.view` — no tenant-wide equivalent exists.
Showing a tenant-wide "documents expiring soon" count to anyone holding
`documents.view` (which a plain Employee also holds, for their own
records) would hand a self-service user an organization-wide figure
they have no reason to see — precisely the "dashboard becomes a
data-leakage shortcut" failure mode you told me to avoid. So `my_documents_expiring_soon`/
`my_documents_recent` are always scoped to the viewer's own linked
employee (`EmployeeDocument::query()->where('employee_id', $employee->id)`),
for every role including Tenant Admin/HR Manager — even though those
roles might reasonably want a tenant-wide figure, the permission model
to gate that safely doesn't exist yet. See "Future" in
[`security.md`](security.md#dashboard-foundation) for what would need
to change first (a `documents.view_all`-equivalent permission).

**Platform Super Admin never calls the tenant dashboard API — a
structural guarantee, not just a frontend choice.** `dashboard.view` is
a tenant-scoped permission; a platform role can never be assigned one
(the same permission-scope guard that's protected every other tenant
permission since Checkpoint 4 — see `HasPermissions`). The route's
`permission:dashboard.view` middleware alone already blocks a platform
admin from `GET /api/v1/dashboard`. `DashboardController::summary()`
adds an explicit `abort_if($user->is_platform_admin, 403, ...)` as
defense in depth anyway, because `BelongsToTenant`'s global scope only
filters queries when a `Tenant` is bound in the container (see
`app/Models/Concerns/BelongsToTenant.php`) — a platform admin reaching
this method with nothing bound would otherwise make every `count()`
below silently run **unscoped across every tenant**. The web `/dashboard`
page, by contrast, deliberately does *not* get blanket
`permission:dashboard.view` middleware — a platform admin must still be
able to open the page (to see the safe "platform dashboard not
available" message), just without it ever calling the tenant-scoped API.

## Settings Foundation (Checkpoint 22)

Reuses the exact "access, not data" two-layer design Checkpoint 21
established for the Dashboard — see
[`security.md`](security.md#settings-foundation) for the permission
model and [`api.md`](api.md#tenant) for the new endpoint's shape.

**A permission catalog pre-provisioned three checkpoints early.**
`tenant.view`, `tenant.update`, `tenant.settings.view`, and
`tenant.settings.update` were already seeded in `PermissionSeeder` —
nobody remembers exactly when, but almost certainly in anticipation of
this exact checkpoint, since nothing used any of them until now (only
Tenant Admin held any of the four, via the blanket "all non-platform
permissions" grant). This checkpoint is the first to actually wire them
to a controller, a route, and deliberate role grants.

**A singleton endpoint, modeled on `/me/*`, not on the generic
`{resource}/{id}` shape every other module uses.** `GET`/`PATCH
/api/v1/tenant` take no route parameter at all — both actions operate
exclusively on `app(Tenant::class)`, the tenant `tenant.matches` already
confirmed the caller belongs to. This is a deliberate structural choice,
not an oversight: there is no legitimate reason for a tenant-scoped
session to ever reference a *different* tenant's ID through this
endpoint, so the shape itself makes tenant-switching impossible rather
than relying on a check to catch it after the fact. Same reasoning as
`MeController`'s `/me/employee` (Checkpoint 11) — "always your own,"
structurally.

**Two tenant permissions were flagged as a real gap and approved before
building anything.** No `TenantController`, `TenantResource`, or route
existed anywhere before this checkpoint — a genuine blocker for goal 4
("basic tenant profile view/edit"), not a nice-to-have. Per your
"stop and flag before deciding" instruction, this was surfaced and
approved explicitly rather than built silently. The resulting endpoint
is deliberately minimal: `UpdateTenantRequest` defines a validation rule
for exactly one field, `name` — `subdomain`/`status`/`tenant_id`/
`created_at`/`updated_at`/`deleted_at` are structurally absent from the
rules, so a request body containing any of them simply has those keys
dropped by `FormRequest::validated()` before the controller ever sees
them, never partially applied. Confirmed live: a `PATCH` sending `name`,
`subdomain`, and `status` together only changed `name` — the other two
came back unchanged in the same response.

**`tenant.settings.view` decouples "can see the Settings page" from
"can see any particular section," exactly like `dashboard.view`.** The
landing page (`SettingsController::index()`) checks
`tenant.settings.view` explicitly in the controller — not blanket
`permission:` middleware — for the identical Platform-Super-Admin reason
as `/dashboard`: a platform role can never hold a tenant-scoped
permission, but a platform admin must still be able to open the page
(to see a safe static message). Every section card the frontend renders
is then independently gated by its own, more specific permission
(`tenant.view` for Company Profile, `users.view`/`roles.view` for
Users & Access, `document_categories.view`, `leave_types.view`,
`audit.view`) — holding `tenant.settings.view` and nothing else
produces a landing page with zero section cards, not an error.

**Sections with no natural permission get the coarsest safe fallback,
not an invented one.** "Integrations" has no real data and no dedicated
permission — rather than inventing an `integrations.view` key for a
page that currently shows nothing, it falls back to the same
`tenant.settings.view` umbrella check the landing page itself uses.
"Billing & Subscription" goes one step further: no route exists for it
at all, just a static, unlinked card on the landing page — inventing a
placeholder route with no content and no specific permission would have
been the "broken link" your instructions explicitly warned against.

**"Users & Access" and "Roles & Permissions" originally shared one
placeholder destination page** — superseded in Checkpoint 23, which
turned `/settings/access` into a real hub linking to dedicated
`/settings/access/users` and `/settings/access/roles` pages. See
"Users & Access Management UI" below.

## Users & Access Management UI (Checkpoint 23)

The first checkpoint to build against models that structurally cannot
rely on the tenant-isolation pattern every other module uses — see
[`security.md`](security.md#users--access-management-ui) for the full
security model.

**`User` and `Role` don't use `BelongsToTenant` — a pre-existing,
deliberate design decision (Checkpoint 3/4), not something introduced
here.** Login has to identify a user by email before any tenant context
exists for that request, and Platform Super Admins need cross-tenant
visibility for future platform tooling — a global scope that
auto-filtered every query by the currently-resolved tenant would break
both. The consequence for this checkpoint: `UserController`/`RoleController`/
`UserRoleController` cannot lean on a global scope as their tenant
boundary at all — every single query manually adds
`where('tenant_id', app(Tenant::class)->id)` (plus, for `User`,
`where('is_platform_admin', false)`; for `Role`,
`where('is_platform_role', false)`). This is the *primary* defense in
these three controllers, not defense-in-depth layered on top of
something else — a mistake here would be a real cross-tenant or
platform-admin data leak, not a redundant safeguard failing. Every
`show()`/mutation additionally repeats the check via an explicit
`abort_if($target->is_platform_admin, 404)` /
`abort_if($target->is_platform_role, 404)` guard, so even a future
refactor that accidentally weakens the query filter still can't reach a
platform-scoped record through a tenant route.

**The hard part — role assignment's own safety rules — already
existed.** `User::assignRole()`/`removeRole()` (Checkpoint 4/5) already
reject platform-vs-tenant scope mismatches and cross-tenant role
assignment, and already write `role.assigned`/`role.removed` audit
logs. `UserRoleController` doesn't reimplement any of this — it adds
exactly one new safeguard on top (`TenantAdminProtectionService`) and
otherwise just calls the existing, already-tested model methods. This
is why the new controller is thin: the security-critical logic was
mostly already built for a different reason (Checkpoint 4's original
RBAC foundation), and this checkpoint's job was building a safe UI on
top of it, not inventing new authorization logic from scratch.

**One rule, one method, two call sites.** "Never leave a tenant without
an active Tenant Admin" is checked identically whether the dangerous
action is a status change (deactivating/suspending the last admin) or
a role removal (stripping the `tenant-admin` role from the last
holder) — both call `TenantAdminProtectionService::wouldLeaveTenantWithoutAdmin()`,
which asks one question: "does at least one *other* user in this
tenant hold the `tenant-admin`-slugged role?" Deliberately broader than
the literal "cannot deactivate *themselves*" instruction — a second
admin (or a bug) deactivating the *other* sole remaining admin is
exactly as dangerous, so the check applies regardless of who's
performing the action. Identified by the fixed, seeded role slug
`tenant-admin`, not a permission-count heuristic — this app has exactly
one canonical "admin" role per tenant by construction (`RoleSeeder`),
so there's a real, stable concept to check against rather than an
inferred one.

**Employee linking UI adds no new backend surface at all.** `POST`/`DELETE
/employees/{employee}/link-user`/`unlink-user` (Checkpoint 11) already
enforce every rule this checkpoint needed (cross-tenant rejection,
terminated-employee rejection, already-linked-employee rejection,
already-linked-user rejection) — the User detail page's link/unlink UI
is a pure frontend addition reusing those exact endpoints. The employee
picker filters out `terminated` employees client-side (a real,
available `EmployeeResource` field) but can't filter out
*already-linked* employees, since `EmployeeResource` doesn't expose
`user_id` — picking an already-linked employee simply surfaces the
existing backend validation's clear error message instead, which is
the correct place for that check to live regardless (backend remains
authority, per Refinement 9).

## Audit Log Viewing UI (Checkpoint 24)

The first checkpoint to build a *read* surface on top of data that has
existed since Checkpoint 5 — `audit_logs` has been written to on every
sensitive action since the very first RBAC checkpoint, with nothing
reading it back until now. See
[`security.md`](security.md#audit-log-viewing-ui) for the full security
model.

**`AuditLog` joins `User`/`Role` as a model that structurally cannot
rely on `BelongsToTenant`** — audit events happen in contexts (login,
CLI, seeders) where an ambient bound tenant would be unreliable, so
`AuditLogger` always takes an explicit `tenant_id` instead (a design
decision from Checkpoint 5, unchanged here). `AuditLogController`
follows the exact same pattern established in Checkpoint 23: manual
`where('tenant_id', app(Tenant::class)->id)` filtering as the *primary*
tenant boundary, plus an explicit `abort_if($user->is_platform_admin, ...)`
guard as defense in depth against the same failure mode (an unbound
`Tenant` silently producing an unscoped query for a platform admin).

**"Read-only" was mostly already true before this checkpoint — this
just adds the read.** `AuditLog::save()` on an existing row and
`delete()` both throw `RuntimeException` at the model layer
(Checkpoint 5) — there was never a way to make audit logs mutable, this
checkpoint didn't need to add any new safeguard for that. What's new is
purely additive: `index()`/`show()`, no `store()`/`update()`/`destroy()`
anywhere, confirmed by a structural test
(`test_no_audit_log_write_routes_exist`) that inspects the registered
route list itself for any `POST`/`PUT`/`PATCH`/`DELETE` method on an
`audit-logs` URI, rather than just trusting that none were written.

**A masking gap that existed for three checkpoints, closed here, not
there.** `AuditLogger::mask()` (Checkpoint 5, extended in Checkpoint 12)
only ever scrubbed `old_values`/`new_values` at write time —
`metadata` was deliberately left unmasked, on the stated assumption
that callers would only ever put "small, safe contextual tags" there.
That assumption mostly held (reviewed across every module's audit call
sites while researching this checkpoint), but "mostly" isn't a security
boundary — a single future call site putting something sensitive into
`metadata` would have shipped unmasked with nothing to catch it. Rather
than retroactively auditing every historical `AuditLogger::log()` call
site for compliance (fragile, and wouldn't protect against the *next*
one either), `AuditValueSanitizer` masks `metadata` the same way
`old_values`/`new_values` already were, applied uniformly at the
read/`Resource` layer — this protects every future metadata value too,
not just the ones already reviewed.

**A deliberately broader pattern list than `AuditLogger`'s own,
accepting false positives on purpose.** `AuditValueSanitizer`'s pattern
list includes `key`, `session`, `cookie`, `authorization`, `iban`,
`medical`, and more that `AuditLogger` never needed. This does mean a
harmless field like `permission_key` (from `role.assigned`/
`permission.granted` audit entries, Checkpoint 4) gets masked purely
because it contains the substring `key` — a known, accepted false
positive, not a bug. Preferring to over-mask a handful of harmless
fields is the correct tradeoff for a sanitizer whose entire job is
catching values nobody explicitly reviewed.

**Actor/target names are resolved client-side, reusing an existing
endpoint, not a new backend join.** `AuditLogResource` returns only
`actor_user_id`/`target_user_id` (plain integers) — no name, no
enrichment query. The frontend fetches the already-existing,
already-tested `GET /api/v1/users` (Checkpoint 23) once per page load
and builds an ID→name lookup map client-side (`formatActorRef()`,
mirroring the `formatEmployeeRef()` pattern from Checkpoint 18) —
falling back to `System` for system-actor entries or a plain `User #N`
reference if a name can't be resolved (e.g. a since soft-deleted user,
absent from that endpoint's default query). No new backend surface,
no cross-tenant lookup risk, since `/api/v1/users` was already
tenant-scoped for its own reasons.

## Document Categories & Leave Types Admin UI (Checkpoint 25)

The first checkpoint since the Dashboard (Checkpoint 21) to need no new
backend endpoint at all — both APIs (Checkpoint 9, Checkpoint 12) were
already complete, tested, and using the standard, well-established
tenant-isolation pattern (`BelongsToTenant` global scope + an explicit
controller check), not the "manual filtering is the primary defense"
situation `User`/`Role`/`AuditLog` needed in Checkpoints 23/24. This
checkpoint's entire job was building an admin UI on top of what already
existed, plus one small, deliberate Resource tightening.

**`created_by`/`updated_by` removed from two Resources that had carried
them since their original checkpoints.** `DocumentCategoryResource`
(Checkpoint 9) and `LeaveTypeResource` (Checkpoint 12) both returned raw
numeric user IDs for these two fields — harmless at the time (no
consumer used them), but exactly the kind of "internal field with no
UI purpose" this checkpoint's instructions asked to drop. Checked before
removing: no existing test asserted either field's presence in a JSON
response (the one `created_by` reference anywhere in the test suite,
`LeaveTypeApiTest`, asserts the *database row*, not the API response) —
safe to remove, confirmed by re-running both modules' full existing
test suites unchanged afterward.

**List + Create + Edit, no detail page — a genuine simplification, not
a shortcut.** Unlike Employees/Leave/Documents/Policies (each complex
enough to need a bare-metadata detail view before actions), a document
category or leave type has so few fields that the Edit form already
shows everything worth showing. Building a third page that mostly
duplicates Edit's own field list would be pure ceremony.

**`max_days_per_year`'s null-handling is the one place this checkpoint
deliberately breaks its own established form convention.** Every other
optional field in every Create/Edit form across this app follows the
same rule: if the user leaves it blank, omit the key entirely (meaning
"don't change this"). `max_days_per_year` on the Leave Type Edit form
is the sole exception — a blank value is sent as an *explicit* `null`,
because `StoreLeaveTypeRequest`/`UpdateLeaveTypeRequest`'s
`'max_days_per_year' => ['nullable', 'integer', ...]` rule (no
`sometimes`) means an *absent* key leaves whatever value was already
there untouched, while an *explicit* `null` genuinely clears it. Without
this special case, a leave type that was ever given a numeric cap could
never be turned back into "unlimited" again through this UI — a subtle
but real one-way door that the Create form doesn't share (a brand-new
leave type has no old value to accidentally preserve, so its blank
`max_days_per_year` is simply omitted, letting the database column's
own default apply, which is already `null`).

**Editing a leave type's configuration never touches existing
`LeaveBalance` rows — this is a property of the schema, not something
this checkpoint had to enforce.** `leave_types` and `leave_balances` are
separate tables with no cascading update trigger between them (confirmed
by reading `LeaveTypeController::update()` — it only ever calls
`$leaveType->save()`, nothing balance-related). The Edit form's helper
text ("Changing this does not affect leave balances already issued")
is purely informational, documenting a guarantee that was already true,
not a new safeguard being added.

## Demo Readiness & UI Polish (Checkpoint 26)

No new business module — this checkpoint's entire job was making the
ten already-built modules (Dashboard, Employees, Leave, Documents,
Policies, Settings, Users & Access, Security & Audit, Document
Categories, Leave Types) feel complete and demo-ready, plus fixing two
concrete, pre-existing rough edges a systematic review turned up.

**Two real bugs found and fixed, not manufactured busywork.** A
targeted review across headers/back-links/badges/empty/loading/error
states/table mobile-wrapping/responsive grids found the app already
consistent (expected, since every page across Checkpoints 17–25 was
built with the same shared component set and conventions) — except for
two things:

1. **`Sidebar.tsx`'s "Settings" nav link was still gated on
   `employees.update`**, a permission that predates Checkpoint 22's
   introduction of `tenant.settings.view` as the actual gate for
   reaching `/settings`. HR Officer and Auditor both hold
   `tenant.settings.view` (and, for Auditor, `audit.view`) but never
   held `employees.update` — so both roles could reach `/settings` by
   URL (the real, unchanged server-side gate) but the sidebar never
   showed them the link. Fixed by changing the nav link's permission
   check to `tenant.settings.view`, matching the actual route gate.
   Server-side security was never the problem here and nothing about
   it changed — this was purely the nav's own visibility hint being
   stale.
2. **The Settings hub (`Settings/Index.tsx`) still marked Users &
   Access, Roles & Permissions, Document Categories, Leave Types, and
   Security & Audit as "Coming later"**, even though all five were
   fully built in Checkpoints 23–25. Only Integrations (and the static,
   unlinked Billing & Subscription card) are genuinely not built yet.
   Left uncorrected, every demo of the Settings hub would visually
   undersell finished work as unfinished. Fixed by flipping
   `comingLater` to `false` on the five sections that already exist.

**`DemoDataSeeder` (new) adds realistic, non-excessive UESL-tenant
data** — departments/positions/locations, 12 employees (four linked to
real login accounts, a full manager tree, one Inactive example), 3 leave
types with consistent balances and a pending/approved/rejected leave
request (the pending one deliberately belongs to the Line Manager demo
account's direct report, so the live smoke test's "Line Manager can
approve only direct-report leave" check has a real row to exercise), 3
document categories with a normal/sensitive/expiry-required/expiring-soon
document set, and 3 policies covering all five required acknowledgement
states (draft, published-unassigned, and one published+assigned policy
carrying both a pending and an acknowledged row). Every row is plain
Eloquent creation via `firstOrCreate`/`updateOrCreate` — idempotent, and
writes no audit log itself (audit entries come from `UserSeeder`'s real
`assignRole()` calls, plus whatever a live login naturally generates
during the demo — see `docs/demo-guide.md`). Called from
`DatabaseSeeder` after `UserSeeder`; `airpeace`/`ibom` are untouched, so
the tenant count doesn't grow.

**`UserSeeder` gained three demo logins** —
`hr.officer@uesl.peopleos.test`, `line.manager@uesl.peopleos.test`,
`auditor@uesl.peopleos.test` — closing a real gap every prior
checkpoint's live smoke test had to work around with a throwaway
`tinker`-created account that `migrate:fresh --seed` then discarded.
`admin@uesl.peopleos.test` (the pre-existing Tenant Admin login) was
kept as-is rather than duplicated under a different email, since the
checkpoint's own instructions explicitly allow "whatever convention
already exists in the project" and creating a second Tenant Admin
account under a new address would have been exactly the kind of
duplicate-user situation the checkpoint asked to avoid.

**The build-size advisory is resolved, not just documented as
acceptable** — see "Page resolution is lazy, one chunk per page" above.
Root cause was `app.tsx`'s eager glob, not genuine app bulk; the fix is
the standard Inertia+Vite lazy-resolution pattern, verified by `tsc
--noEmit`, `vite build`, and the full live smoke test (since this is an
async runtime behavior change, not just a build config number).

**`docs/demo-guide.md` (new)** is the practical companion to this
section — local setup, demo users/roles, a suggested login sequence, a
per-module demo flow, what each role should see, known limitations, and
what not to demo yet.

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

## Deployment Topology (Checkpoint 27)

Local development's subdomain-per-tenant resolution (`ResolveTenant`
middleware, `config('tenancy.base_domain')` driven by `APP_DOMAIN`) is
not Laragon-specific — it's the actual application architecture, and a
production deployment needs the same shape at real-world scale:
wildcard DNS for the real domain, a web server virtual host matching
that wildcard, and a wildcard (or automated per-subdomain) TLS
certificate. None of this is a code change — `ResolveTenant` already
works purely off the `Host` header and `APP_DOMAIN`, so the same
codebase serves both `*.peopleos.test` locally and `*.yourdomain.com`
in production without modification, provided DNS/vhost/TLS are set up
to match. Full operational detail (exact DNS/vhost/cert requirements,
session-domain behavior, why `tenant.matches` remains the actual
security boundary regardless of DNS/cookie configuration) lives in
[`docs/deployment.md`](deployment.md) — this section only establishes
that the *architecture* transfers unchanged, not the *procedure*.

`php artisan route:audit-tenant-scoping` (new — see
`app/Console/Commands/AuditTenantRouteScoping.php`) formalizes a check
that used to be a scratch-directory script re-created by hand before
every checkpoint: every `auth`-protected route must also carry
`tenant.matches`. It reads Laravel's own registered route table
directly (`Route::getRoutes()`), not a pre-generated JSON snapshot, so
it stays correct automatically as routes are added — no maintenance
step required when a future checkpoint adds a new authenticated route.

## RBAC Role & Permission Management UI (Checkpoint 28)

Adds create/edit/permission-assignment to Checkpoint 23's read-only
role list — `GET /api/v1/roles/{role}` (show), `POST /api/v1/roles`
(create), `PATCH /api/v1/roles/{role}` (update),
`POST`/`DELETE /api/v1/roles/{role}/permissions(/{permission})`
(assign/remove). No new pages beyond what Checkpoint 23 already
scaffolded at `/settings/access/roles` — three new routes
(`/create`, `/{role}`, `/{role}/edit`) plus permission management
folded into the detail page rather than a fifth separate page. See
`docs/security.md` for the full security-design writeup; this section
covers the schema/architecture decisions.

**`roles.is_system_role` (new column) is the load-bearing design
decision this checkpoint made.** The `roles` table previously had no
way to distinguish a seeded role from an admin-created one —
`is_platform_role` only separates platform-vs-tenant scope, not
built-in-vs-custom within the tenant scope. Added as a plain boolean,
default `false`, backfilled `true` for every pre-existing row at
migration time, and `RoleSeeder` now sets it explicitly on every role
it creates going forward (so a bare `db:seed` re-run against an
already-migrated database still marks new seeded rows correctly, not
just relying on the migration's one-time backfill).

**Every system role is permanently locked to view-only — no runtime
"is this safe" calculation exists.** `RoleController::update()` and
both `RolePermissionController` actions call `ensureNotSystemRole()`
(403) before doing anything else. This was the explicitly approved
"safer MVP" alternative to building logic that decides whether removing
a given permission from Tenant Admin would leave the tenant without an
effective admin path — that calculation is genuinely hard to get
right and even harder to fully test, so this checkpoint doesn't attempt
it. The tradeoff: even an obviously-harmless edit to a built-in role
(e.g. adding `documents.view` to HR Officer) isn't possible through
this UI yet — a real limitation, documented as such, not hidden.

**Two permission-mutation methods on `Role`, deliberately not one.**
`givePermissionTo()` (existing, Checkpoint 4/5) stays exactly as it
was — used only by `RoleSeeder`'s bulk catalog-building loop, and
deliberately un-audited, since logging each of its ~100+ calls per
`migrate:fresh --seed` would flood the audit log with seeding noise.
`assignPermission()`/`removePermission()` (new) wrap the same
underlying scope-check logic but add audit logging, and are the only
methods `RolePermissionController` calls. Same shape as
`HasPermissions`'s `assignRole()`/`removeRole()` (user-level, always
audited) vs. how a seeder populates a user's roles directly — this
checkpoint just makes the equivalent split explicit at the role-
permission level too.

**No role deletion, at all, for any role.** Not attempted this
checkpoint — the simplest possible guarantee that "Tenant Admin
protected from deletion" holds is that nothing can be deleted yet.
`roles.delete` remains a seeded-but-unused permission key, same
category as `policies.export_acknowledgements` and a few others this
app has seeded ahead of the feature that will eventually use them.

## Employee Lifecycle Foundation (Checkpoint 32)

Adds management UI and API for three lookup entities that already
existed at the schema level since Checkpoint 6 (`departments`,
`positions`, `locations` — each with only `id`, `tenant_id`, `name`,
already FK-referenced from `employees`) but had zero API/controller/
route/permission/UI surface until now. See
[`security.md`](security.md#employee-lifecycle-foundation) for the
full security model and [`api.md`](api.md#departments-positions-locations)
for the route reference.

**Deliberately scoped to lookup-entity CRUD only, not a lifecycle
workflow.** "Employee Lifecycle" in this checkpoint's name refers to
the organisational structures (department/position/location) an
employee's lifecycle will eventually move through, not onboarding/
offboarding/org-chart/payroll workflows themselves — all explicitly
named as future work, none built here. `DepartmentController`/
`PositionController`/`LocationController` mirror `DocumentCategoryController`'s
(Checkpoint 9) shape exactly: the fourth top-level, non-nested,
tenant-scoped admin resource in this app, not a new pattern.

**Employment Type stays a fixed enum, deliberately not converted to a
fourth lookup table this checkpoint.** Departments/positions/locations
are tenant-specific organisational structures that legitimately vary
per tenant; employment type (`full_time`/`part_time`/`contractor`/
`intern`/`consultant`) is a stable, universal classification that
already worked safely as `App\Enums\EmploymentType`. Converting it
alongside the other three would have been scope creep beyond what was
approved — a future checkpoint can revisit this if a real tenant-
specific employment-type need ever surfaces.

**Schema additions are additive only, no data rewrite.** Each of the
three tables gained `slug`, `description`, `status`, `created_by`,
`updated_by` via migration — nullable/defaulted columns backfilled for
existing rows in the same migration (via plain Eloquent with
`withoutGlobalScopes()`, never raw driver-specific SQL), then a unique
`(tenant_id, slug)` index added after backfill. `status` is a plain
two-value enum (`DepartmentStatus`/`PositionStatus`/`LocationStatus`,
each `Active`/`Inactive` only) — archiving one of these entities is a
soft toggle, on top of (not instead of) the existing `SoftDeletes`
soft-delete already present on all three models.

**Slug is always server-generated, never accepted from the frontend.**
`StoreDepartmentRequest`/`UpdateDepartmentRequest` (and the Position/
Location equivalents) only validate `name`/`description` (create) or
`name`/`description`/`status` (update) — `slug` has no rule at all, so
a request body containing one has it silently dropped before the
controller ever sees it. Each controller's private `uniqueSlugFor()`
helper derives a slug from `name` via `Str::slug()`, then appends a
numeric disambiguation suffix (`-2`, `-3`, ...) if the tenant already
has a matching slug — checked via `withoutGlobalScopes()` so a
soft-deleted row's slug still counts as taken, preventing a slug reuse
collision against index history.

**A real, pre-existing validation gap in Employee closed as part of
this checkpoint, not a new feature.** `StoreEmployeeRequest`/
`UpdateEmployeeRequest`'s `department_id`/`location_id`/`position_id`
`Rule::exists()` checks (present since Checkpoint 6) validated only
tenant ownership, never excluding archived (`status: inactive`) or
soft-deleted rows — the exact same class of gap Checkpoint 9 found and
fixed for `document_categories` (`Rule::exists()` is a raw DB check
that bypasses Eloquent's `SoftDeletes` global scope and any status
column entirely). Fixed by adding
`->where('status', DepartmentStatus::Active->value)->whereNull('deleted_at')`
(and the Position/Location equivalents) to each rule. Verified an
employee already assigned to a department that is *later* archived is
unaffected (`test_updating_unrelated_employee_field_does_not_revalidate_an_already_archived_department`)
— the fields are `nullable` with no `sometimes`, so they're only
re-validated when a request actually supplies them, never retroactively
on an unrelated field update.

**`EmployeeResource` gained nested `{id, name}` objects, keeping raw
IDs for backward compatibility.** `department`/`location`/`position`
each resolve to `{id, name}` (or `null` if unassigned) via
`EmployeeController` unconditionally eager-loading all three
relations (`->with(['department', 'location', 'position'])` on
`index()`, `->load([...])` after `store()`/`show()`/`update()`) — not
gated by `whenLoaded()`, since the controller always loads them. The
raw `department_id`/`location_id`/`position_id` fields stay in the
response unchanged, so nothing that already depended on the bare ID
breaks.

**Permission grants follow the exact tier structure Document
Categories established, extended to three entities:** Tenant Admin
(wildcard, unchanged), HR Manager (`view`/`create`/`update`/`delete`
on all three), HR Officer (`view`/`create`/`update`, no `delete`),
Line Manager and Auditor (`view` only), Employee (none — an employee
sees their own department/position/location only via the resolved
names on their own linked employee record, never a direct lookup
permission). Checked explicitly before granting broadly: HR Manager is
the only non-Tenant-Admin role holding `employees.create`/
`employees.update`, and it already receives full department/position/
location access, so there's no permission-dependency gap where a role
could create/edit an employee but couldn't populate the new dropdowns.

**Frontend fetches active-only records for the Employee form's three
new pickers, filtered client-side, not via a server-side query
parameter.** `Employees/Create.tsx`/`Edit.tsx` call the existing
`GET /departments`/`/positions`/`/locations` list endpoints (no new
API surface for this) and filter `.filter(x => x.status === 'active')`
before rendering `<option>` sets — archived entities are excluded from
selection without needing a dedicated `?status=active` query parameter
on three already-simple list endpoints. The backend's own archived-row
validation is what actually prevents an archived ID from being
accepted regardless of what the dropdown offers, per Refinement 9 (the
frontend filter is a convenience, not the enforcement).

## Onboarding & Offboarding Foundation (Checkpoint 33)

The first genuine multi-actor **workflow-shaped** module since Leave
Management (Checkpoint 12) — two new tables,
`employee_lifecycle_processes` and `employee_lifecycle_tasks`, added
per your approved minimal schema. See
[`security.md`](security.md#onboarding--offboarding-foundation-checkpoint-33)
for the full security model and [`api.md`](api.md#lifecycle-processes--tasks)
for the route reference.

**One generic resource, two `type` values, not two parallel modules.**
Onboarding and Offboarding are not separate tables, controllers, or
permission sets — a `LifecycleProcess` has a `type` column
(`onboarding`/`offboarding`) and everything else (schema, permissions,
routes, UI) is shared. This mirrors the reasoning already applied to
Departments/Positions/Locations sharing one CRUD shape in Checkpoint
32: a genuinely identical structure doesn't need parallel
implementations just because the two concepts have different names in
the business domain.

**Status transitions are centralized via `canTransitionTo()`, the
exact pattern `LeaveRequestStatus` established in Checkpoint 12.**
`LifecycleProcessStatus` (`draft` → `in_progress` → `completed`/
`cancelled`, both terminal) and `LifecycleTaskStatus` (`pending` →
`in_progress`/`completed`/`skipped`, the latter two terminal) each
carry their own `allowedNextStates()`/`canTransitionTo()` pair, checked
in `UpdateLifecycleProcessRequest`/`UpdateLifecycleTaskRequest`'s
`withValidator()` against the *route-bound record's current status* —
not just "is this a valid enum value." A terminal process/task rejects
every further mutation outright (422), not just illegal transitions —
per your explicit rule 9 ("completed/cancelled process should not
accept normal task updates").

**`LifecycleVisibilityService` had to solve a problem `LeaveVisibilityService`
never faced: two roles with the *identical* permission set needing
*different* visibility.** Every prior visibility-scoped module (Leave)
had a distinct permission key per tier (`leave.view`/`leave.view_team`/
`leave.view_all`). Your explicit "simpler generic" permission
recommendation for this checkpoint means Line Manager and Employee
both hold exactly `lifecycle.view` + `lifecycle.complete_task` — no
permission key distinguishes "see my direct reports' processes" from
"see only tasks assigned to me." `hasUnrestrictedAccess()` resolves
this from relationship data instead: holding any *write* permission on
the resource (`create`/`update`/`delete`/`assign_task`) means HR/Admin-
tier (see everything); holding `view` but not `complete_task` at all
means Auditor-tier (read-only, see everything); the one remaining
case — `view` + `complete_task`, nothing else — is scoped to the
caller's own direct reports (via the existing `ManagerHierarchyService::
directReportsOf()`, Checkpoint 14) and/or tasks assigned directly to
them. This is a judgment call, not something derivable purely from the
approved permission list — documented explicitly rather than silently
decided, per Refinement 9's "flag it before deciding" instruction.

**A genuine, identically-shaped permission gap was found twice while
building the Create-process and Create-task forms, and flagged both
times before fixing.** `GET /api/v1/employees` (the process form's
employee picker) requires `employees.view`; `GET /api/v1/users` (the
task form's assignee picker) requires `users.view`. HR Officer held
neither, despite being granted `lifecycle.create`/`lifecycle.assign_task`
in this same checkpoint — the same "granted an action but not the read
permission the action's own UI depends on" shape as Checkpoint 19's
`document_categories.view` fix. Both were confirmed and approved
individually (not assumed from precedent alone, since `users.view`
exposes a broader/more sensitive resource than `employees.view`) before
granting — view-only in both cases, no create/update/deactivate/
assign_role added.

**Assigning a task is a distinct permission from creating/editing one.**
`lifecycle.assign_task` gates setting/changing `assigned_to_user_id`
specifically — checked explicitly in `LifecycleTaskController::store()`/
`update()`, on top of (not instead of) `lifecycle.create`/
`lifecycle.update` route middleware. Every role holding `create` in
this checkpoint's approved grants also holds `assign_task`, so this
distinction has no visible effect on the seeded demo roles today — it
exists so a future custom role that splits them (e.g., "can add tasks
but assignment stays HR-only") is already safe, not something to
retrofit later.

**No standalone `GET /api/v1/lifecycle-tasks/{task}` endpoint exists —
deliberately, matching your "keep it minimal" instruction.** The
approved API route list has no single-task read route; the Task Edit
page instead fetches the parent process (`GET
/api/v1/lifecycle-processes/{process}`, which already eager-loads
`tasks`) and finds the specific task client-side by ID. Adding a new
route just to avoid one extra property lookup in the frontend would
have been scope creep beyond what was approved.

**Soft-delete/cancel, never a hard delete, for both processes and
tasks — the same rule Checkpoint 32 established for Departments/
Positions/Locations, applied here too.** `DELETE
/lifecycle-processes/{process}` transitions a non-terminal process to
`cancelled` before soft-deleting it (an already-terminal process is
just hidden, its status left alone — "cancelling" a completed process
would be a false statement); `DELETE /lifecycle-tasks/{task}`
soft-deletes only, logged as `lifecycle_task.deleted` — an action name
not in your originally-listed audit actions, added anyway since
under-logging a real mutation is worse than slightly exceeding the
minimum list.

**Audit metadata never carries a task's free-text `title`/`description`** —
only `id`/`status`/`process_id`/`assigned_to_user_id`, per your explicit
"do not log sensitive free-text task details if avoidable" instruction.
This is stricter than `AuditLogger`'s own mask-by-pattern fallback
(which would only catch a field if its *name* matched a known-sensitive
substring) — here, the free-text fields are simply never passed to
`AuditLogger::logFor()` as metadata at all, verified directly
(`test_task_description_is_not_stored_in_audit_metadata`).

**No task-template table this checkpoint, per your explicit approval.**
`lifecycle_task_templates` was offered as optional in the proposed
schema; HR adds tasks directly when creating a process instead. A
reusable checklist library is documented future work, not a silent
scope cut — see "Current limitations" in `security.md`.
