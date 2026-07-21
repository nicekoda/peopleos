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

## HR Documents & Letter Generation Foundation (Checkpoint 34)

Two new tables, `hr_document_templates` and `hr_generated_documents`,
added per your approved minimal schema — the same `Policy`/`PolicyVersion`
template-and-instance shape from Checkpoint 20, not a new pattern. See
[`security.md`](security.md#hr-documents--letter-generation-foundation-checkpoint-34)
for the full security model and
[`api.md`](api.md#hr-document-templates--hr-generated-documents) for the
route reference.

**Content-only (Option A, your explicit approved choice) — no PDF/DOCX
library was added this checkpoint.** Neither existed in `composer.json`/
`package.json` before this checkpoint (verified during the gap
analysis, not assumed); adding one was flagged as a separate dependency
decision rather than bundled into this one. `hr_generated_documents.rendered_content`
stores the resolved letter as plain text; `employee_document_id` stays
nullable and unused, the same forward-compatible-placeholder shape
`policy_versions.employee_document_id` already established in
Checkpoint 20 for an analogous "no real file yet" gap.

**Generation is a single action, not a two-step draft-then-render
flow.** `POST /api/v1/hr-generated-documents` both creates the row and
renders `content_template` in one request — there is no intermediate
"draft, unrendered" state in this checkpoint's schema (the `draft`
value in `HrGeneratedDocumentStatus` exists for forward compatibility,
not reachable by any code path yet). Consequently the write route is
gated by `hr_generated_documents.generate`, not `.create` — `.create`
is seeded in the permission catalog (matching your suggested split) but
not wired to any route yet, the same "seeded ahead of use" posture the
existing unused `audit.export` permission already established.

**`PlaceholderRenderer` is deliberately not a template engine.**
`App\Services\HrDocuments\PlaceholderRenderer::render()` calls PHP's
`strtr()` once, with a fixed, hardcoded array of exactly ten
`{{employee.*}}`/`{{tenant.name}}`/`{{today}}` keys mapped to real
values resolved from the `Employee`/`Tenant` models passed in. `strtr()`'s
array form does a single, simultaneous, non-recursive pass over the
subject string — there is no code path from a stored `content_template`
string to Blade compilation, `eval`, reflection, or dynamic
property/method access. A token not in the fixed map (a typo, a
different casing, an attacker-supplied `{{system.env.APP_KEY}}` or
`{{employee.delete()}}`) is simply never matched, so it passes through
completely unchanged — never executed, never a validation error. See
`tests/Unit/PlaceholderRendererTest.php` for the exact cases this
guards (unknown tokens, near-miss casing, null relations rendering as
empty string rather than `null`/a PHP notice).

**`document_type` is a shared enum (`HrDocumentType`), not a free
string or a new lookup table.** The eight example letter types you
listed (employment/offer/confirmation/promotion/warning/exit/reference/
contractor-engagement) are fixed, known values — the same reasoning
`DocumentCategoryStatus`/`DocumentAppliesTo` already apply to a small,
enumerable classification. `hr_generated_documents.document_type` is
copied from the template at generation time (not a live FK-resolved
lookup), so a template edited or archived later never rewrites the
history of documents already generated from it — the same "copy, don't
reference" reasoning `hr_generated_documents.title` also follows when
no override is given.

**Archiving is soft-delete only, on both resources — the
`DocumentCategoryController`/`HrDocumentTemplateController::destroy()`
pattern, not a new one.** A template referenced by existing generated
documents is always safe to archive (`hr_generated_documents.hr_document_template_id`
is `nullOnDelete`, and `GenerateHrDocumentRequest` already excludes
inactive/soft-deleted templates from new generation); a generated
document's own `DELETE` similarly just soft-deletes ("archives") it.
Update endpoints on both resources never accept a `status` field at
all — `UpdateHrGeneratedDocumentRequest` accepts `title` only — so a
status transition can never be smuggled in through a generic update
body, avoiding the need for per-transition validation logic entirely.

**No employee-scoped nesting (`/employees/{employee}/hr-generated-documents`)
— a flat `/hr-generated-documents` resource with an optional
`?employee_id=` filter instead**, matching the `/lifecycle-processes`/
`/lifecycle?employeeId=` convention from Checkpoint 33 rather than the
`/employees/{employee}/documents` nesting from Checkpoint 8. A
generate form needs to *pick* the employee (not have it fixed by the
URL), and an Employee detail page's "HR Documents" link filters the
same flat list — the same shape already proven for Lifecycle, not a
new one invented for this checkpoint.

## PDF Export Dependency Review & Prototype (Checkpoint 35)

A dedicated dependency/environment review preceded any implementation,
per your explicit instruction — no library was added until you approved
one. See [`security.md`](security.md#pdf-export-dependency-review--prototype-checkpoint-35)
for the full security design and [`api.md`](api.md#hr-document-templates--hr-generated-documents)
for the route reference.

**`dompdf/dompdf` (direct), not `barryvdh/laravel-dompdf`.** The
Laravel wrapper adds a facade/config-publishing layer this app doesn't
need — nothing here renders a Blade view through dompdf's integration;
`HrDocumentPdfRenderer` builds its own small, code-owned HTML string
(the same "own the whole pipeline, don't adopt a general-purpose engine
for a narrow job" reasoning `PlaceholderRenderer` already established
in Checkpoint 34), so the wrapper's only value — Blade integration —
is unused. Depending on `dompdf/dompdf` directly also avoids coupling
this app's PDF feature to a *second* package's own Laravel-version
support cadence on top of dompdf's own.

**Headless-browser PDF generation (Browsershot/Puppeteer/wkhtmltopdf)
was ruled out at the review stage, not implemented and then reverted.**
All three require installing and maintaining a browser or standalone
binary on whatever server runs this app — workable on a fully-controlled
VPS, but incompatible with the cheap shared hosting this project's
`docs/quality-gate.md` "GitHub Free" reasoning already treats as a real
constraint to plan around. A single-page text letter has no layout
complexity that would justify that operational cost; `dompdf/dompdf`'s
pure-PHP, no-binary rendering was sufficient.

**Option B (generate-on-demand, never stored) over Option C (generate
and persist).** `HrGeneratedDocumentController::downloadPdf()` calls
`HrDocumentPdfRenderer::render()` synchronously inside the request and
returns the bytes directly — `Storage::disk(...)` is never touched.
This sidesteps every file-lifecycle question (orphaned files, disk
growth, what happens if the underlying `rendered_content` is later
edited) that Option C would raise, at the cost of re-rendering the PDF
on every download rather than paying that cost once — an acceptable
tradeoff for a single-page letter with no images. `hr_generated_documents.employee_document_id`
(added in Checkpoint 34, still unused) remains the intended attach
point if a future checkpoint moves to Option C.

**`HrDocumentPdfRenderer` reuses the "own the entire markup, never
trust content as HTML" rule from `PlaceholderRenderer`/the frontend.**
Every interpolated value (document title, employee name, tenant name,
generated date, and `rendered_content` itself) is passed through `e()`
before being placed in the HTML string this class builds; `rendered_content`
additionally goes through `nl2br()` *after* escaping (never before) so
line breaks render correctly without opening any markup-injection path.
dompdf's `Options::setIsRemoteEnabled(false)` (its own default, set
explicitly rather than relied upon) and `setIsJavascriptEnabled(false)`
mean the rendering pipeline can never fetch a remote URL or execute
script, regardless of what ends up in a future template's `content_template`.

**No new permission was introduced for downloading a PDF.** The route
is gated by the existing `hr_generated_documents.view` — the same
permission that already gates `GET /api/v1/hr-generated-documents/{id}`
(the JSON view). Downloading a PDF rendering of a document you can
already view in full isn't a new capability worth its own permission
key, the same reasoning already applied to policy-version reads in
Checkpoint 20.

## HR Document Template Versioning Foundation (Checkpoint 36)

A new `hr_document_template_versions` table, added per your approved
minimal schema — the same `Policy`/`PolicyVersion` template-and-instance
shape from Checkpoint 20, applied to `HrDocumentTemplate` for the first
time. See [`security.md`](security.md#hr-document-template-versioning-foundation-checkpoint-36)
for the full security model and [`api.md`](api.md#hr-document-template-versions)
for the route reference.

**`content_template` moved entirely to the version; `title`/`description`/`document_type`
deliberately did not — your explicit approved design, not the schema
originally proposed.** A template's catalogue identity (what it's
called, what kind of letter it is) doesn't change between wording
revisions; only the wording itself does. Duplicating title/description/
document_type onto the version (as first proposed) would have raised a
"which is authoritative" question with no clean answer — keeping them
template-only avoids the question entirely. This is a narrower design
than `PolicyVersion` (which does carry its own `title`, distinct from
`Policy.title`) — a deliberate simplification for this checkpoint, not
an oversight.

**Editing a draft version is a genuine new capability beyond what
`PolicyVersion` supports — Policy versions are write-once-then-publish,
with no `PATCH` endpoint for their content at all.** Your explicit
requirement that HR/Admin users be able to edit a draft before
publishing meant adding `PATCH /api/v1/hr-document-template-versions/{id}`,
rejected (422) via `UpdateHrDocumentTemplateVersionRequest::withValidator()`
unless the route-bound version's status is currently `draft` — the
same "checked in withValidator() against the route-bound record's
current status" pattern `UpdateLifecycleProcessRequest`/
`UpdateLifecycleTaskRequest` already established in Checkpoint 33, not
a new one invented here.

**Publishing allows "republishing" an older archived version as a
rollback — the same latitude `PolicyController::publish()` already
has.** `HrDocumentTemplateVersionController::publish()` has no status
guard on the *target* version (only draft versions are the realistic
case, but nothing stops publishing an archived one back), demotes
whichever version was previously published for the same template to
`archived` (never deleted — every version stays queryable history), and
points `hr_document_templates.current_version_id` at the newly
published one. `published_at`/`published_by` are set here, server-side
only, in the exact same request that performs the demotion — never
accepted from client input.

**One new permission, `hr_document_templates.publish`, mirrors
`policies.publish` alongside `policies.update`.** Version list/create/
edit/archive all reuse the existing `hr_document_templates.{view,update,delete}`
keys — a version is the template's own history, not a separate resource
with separate trust, the same reasoning that keeps `POST .../versions`
gated by `.update` rather than `.create` (matching
`PolicyController::storeVersion()`'s use of `policies.update`).

**Single-step template creation was preserved, not replaced with
Policy's two-step shape.** `POST /api/v1/hr-document-templates` still
accepts `content_template` in the request body — your explicit approved
choice, to avoid changing `Create.tsx`'s existing one-request UX. The
controller creates the template row *and* its first version (`published`,
`version_number: 1`) together; `content_template` is stripped from the
validated array before the template itself is created (it was never a
column there) and used only to create the version.

**Generation resolves `current_version_id`, not a live `content_template`
column that no longer exists.** `HrGeneratedDocumentController::store()`
eager-loads `template.currentVersion`, requires it to be non-null *and*
`published` (defense in depth beyond `GenerateHrDocumentRequest`'s own
`whereNotNull('current_version_id')` check — guards the race where a
version is archived between validation and this line), renders from
`$version->content_template`, and stores `hr_document_template_version_id`
alongside the existing `hr_document_template_id`. `rendered_content`
remains generated once and never re-derived — exactly the guarantee
that keeps `HrDocumentPdfRenderer` (Checkpoint 35) completely unaffected
by this checkpoint: it only ever reads `rendered_content`, never the
live template or its versions.

**Backfill is a query-builder data migration, not an Eloquent one —
deliberately.** `2026_07_06_150300_backfill_hr_document_template_versions.php`
uses `DB::table(...)`, not the `HrDocumentTemplate`/`HrGeneratedDocument`
model classes, because a data migration must stay correct against the
schema as it existed *at that point in history*, independent of how the
models evolve afterward (the models, as of this same checkpoint, no
longer even have a `content_template` attribute to read). For every
existing template it creates a `published` version 1 from the
template's current `content_template` and points `current_version_id`
at it; for every existing generated document referencing that template,
it backfills `hr_document_template_version_id` to the same version —
accurate, not a guess, since before this checkpoint a template only
ever had one live `content_template`, so anything generated from it was
necessarily generated from what is now "version 1". Verified directly
(not just assumed) by rolling back the backfill + column-drop
migrations, inserting a raw pre-checkpoint-shaped row, replaying them
forward, and confirming the resulting version/reference/column state —
see `docs/testing.md`.

## HR Document Approval Workflow Foundation (Checkpoint 37)

A single-approver approval workflow for `HrGeneratedDocument`, per your
approved gap analysis. See
[`security.md`](security.md#hr-document-approval-workflow-foundation-checkpoint-37)
for the full security model and
[`api.md`](api.md#hr-generated-document-approval-workflow) for the
route reference.

**Status flow is centralized in `HrGeneratedDocumentStatus::canTransitionTo()`,
the exact `LifecycleProcessStatus` pattern from Checkpoint 33 — not a
new mechanism.** `draft → pending_approval → approved | rejected`,
`rejected → pending_approval` (resubmit), and `archived` reachable from
every non-terminal status including `pending_approval` (unconditional
archiving matches this controller's pre-Checkpoint-37 behavior —
`destroy()` never had a status guard at all — so no new blocking rule
was introduced where none was asked for). `approved` only transitions to
`archived`: never editable, never resubmittable, exactly as specified.

**A newly generated document always starts `draft` — no auto-approve
shortcut for a user who already holds `.approve`, your explicit
approved simplification.** `HrGeneratedDocumentController::store()`
unconditionally sets `status: Draft`; submit + approve is always a
separate, explicit step regardless of who generated it.

**`submit()` handles both the original submission and a resubmission
through one endpoint, distinguished only by audit action.** `draft →
pending_approval` logs `hr_generated_document.submitted`; `rejected →
pending_approval` logs `hr_generated_document.resubmitted` — the
`$fromStatus` captured before the transition is the only thing that
differs, matching your required audit-action list without a second
route.

**Three new permissions, not folded into `.update`.** `hr_generated_documents.{submit,approve,reject}`
exist specifically so HR Officer can hold `.submit` without ever being
able to hold `.approve`/`.reject` — self-approval is structurally
impossible for that role, not just discouraged by convention.

**Editability is a status gate on the existing update endpoint, not a
new one.** `UpdateHrGeneratedDocumentRequest::withValidator()` rejects
(422) a title edit unless the route-bound document's status is
`draft` or `rejected` — your explicit approved rule (not editable while
`pending_approval` or once `approved`). Same "withValidator() against
the route-bound record's current status" pattern
`UpdateHrDocumentTemplateVersionRequest` already established in
Checkpoint 36.

**PDF export gets a plain-text watermark banner for anything not
`approved` — Option A, your approved choice over blocking non-approved
downloads entirely.** `HrDocumentPdfRenderer::watermarkBanner()` adds
one extra `<div>` (no images, nothing that could pass for an official
seal) reading e.g. "DRAFT — NOT YET SUBMITTED FOR APPROVAL" or
"PENDING APPROVAL — NOT YET APPROVED", conditioned purely on the
document's own `status` — no new permission, no new route, the
existing `hr_generated_documents.view`-gated download still works
identically for every status, just visibly labeled when it isn't final.

**Rejection reason is stored and returned, but never audited.**
`rejection_reason` is a real column, exposed on `HrGeneratedDocumentResource`
(the whole point of rejecting is that the submitter can see why — hiding
it would defeat the feature) but never passed to `AuditLogger::logFor()`
as metadata, the same "free-text content never reaches the logger"
discipline already applied to lifecycle task descriptions and this
module's own `rendered_content`.

**Backfill maps every pre-existing `generated` document to `approved`,
with `approved_at`/`approved_by` copied from `generated_at`/`generated_by` —
your approved reading of "already finalized" under the old content-only
model.** `submitted_at`/`submitted_by` stay null — these documents were
never actually submitted through a workflow that didn't exist yet, and
backfilling a submission that never happened would be a fabrication,
unlike the generator-as-approver proxy which is the only real actor on
record. Verified directly (not just assumed) the same way Checkpoint
36's version backfill was: rolling back the status-backfill migration,
inserting a raw pre-Checkpoint-37 `generated` row via the query
builder, replaying the migration forward, and confirming the resulting
`approved`/`approved_at`/`approved_by`/`submitted_at` state — see
`docs/testing.md`.

## HR Document Template Library & Starter Templates (Checkpoint 38)

Eight starter templates, seeded and duplicable, per your approved gap
analysis (Option A). See
[`security.md`](security.md#hr-document-template-library--starter-templates-checkpoint-38)
for the full security model and
[`api.md`](api.md#hr-document-template-duplication) for the route
reference.

**Option A over Option B — tenant-specific seeded rows, no global
library table, your explicit approved choice.** A starter template is
a completely ordinary `HrDocumentTemplate` + `HrDocumentTemplateVersion`
pair, seeded via `DemoDataSeeder::seedHrDocumentTemplates()` using the
exact `firstOrCreate()` idempotent-seeding pattern `seedPolicies()`/
`seedDocumentCategories()` already established — no `is_starter` flag,
no distinguishing column, nothing that would need its own access rules.
Once seeded, HR can edit, version, archive, or duplicate a starter
template exactly like one they created themselves. Seeded for `uesl`
only, matching this seeder's existing "uesl gets rich demo data,
airpeace/ibom stay minimal" scope for every other module — this
checkpoint does **not** hook starter templates into general
tenant-creation (`TenantSeeder`), which would be the actual Option B
"reusable for future tenants" concern, deliberately deferred.

**The 8 example titles map onto the 8 existing `HrDocumentType` cases
with no new enum value needed** — including the one non-obvious
mapping: "Probation Completion Letter" uses `ConfirmationLetter`
(confirming an employee has passed probation is exactly what that case
already represents), and "Employment Confirmation Letter" (a general
employment-verification letter) uses `EmploymentLetter`.

**Duplication mirrors `store()`'s single-step create-with-version-1
flow exactly, your approved choice over a draft-requiring-publish
copy.** `HrDocumentTemplateController::duplicate()` copies
`description`/`document_type` as-is, generates a unique
`"{title} (Copy)"` (then `"(Copy 2)"`, `"(Copy 3)"`... on collision —
the same auto-increment-on-collision idea `HrDocumentTemplateVersionController`
already uses for `version_number`) title/slug pair, and creates version
1 from the **source's current published version's** `content_template`
(not just "the latest version" — specifically the live one, since
that's the only version guaranteed to represent what the source
currently produces), published immediately. A duplicate is a fully
working, generation-ready template the moment the request completes —
no separate publish step required, consistent with every other
template create path in this app.

**No new permission — duplication reuses `hr_document_templates.create`,
per your explicit instruction.** Duplicating is creating a new
template pre-filled from an existing one; the trust level is identical
to a blank create, so a distinct `.duplicate` permission would only add
a permission-matrix row with no behavioral difference from `.create`.

## Recruitment & Applicant Tracking Foundation (Checkpoint 39)

A simple internal ATS foundation, per your approved gap analysis. See
[`security.md`](security.md#recruitment--applicant-tracking-foundation-checkpoint-39)
for the full security model and
[`api.md`](api.md#recruitment--applicant-tracking) for the route
reference.

**4-table split, your explicit approved choice over a 2/3-table
combined design.** `recruitment_jobs` (the opening — title,
department/position/location FKs, `employment_type` reusing the
existing `EmploymentType` enum unchanged, `description`, and a
`draft/open/on_hold/closed/cancelled` `RecruitmentJobStatus` with a
`canTransitionTo()`/`allowedNextStates()` guard mirroring
`LifecycleProcessStatus` exactly), `recruitment_applicants` (the
person's identity — name/email/phone/source), `recruitment_applications`
(a specific person's application to a specific opening — the FK pair
`recruitment_job_id`/`recruitment_applicant_id`, an `ApplicationStage`
pipeline enum, a separate `ApplicationStatus` active/archived flag,
`cover_letter`, and the `ready_for_conversion` milestone flag), and
`recruitment_application_notes` (internal-only, `visibility` stored as
a plain string rather than an enum since only `'internal'` is ever
written this checkpoint — reserved for a future second tier, not
built). Splitting applicant from application costs nothing this
checkpoint and avoids a schema migration later if "the same person
applies to a second opening" or a candidate directory ever becomes a
real need — but this checkpoint still only supports one applicant per
application: there's no dedupe/merge-by-email logic, so submitting
twice for the same person creates two independent `recruitment_applicants`
rows (documented limitation below).

**Applicant + application are created together in one request —
`JobApplicationController::store()` mirrors
`HrDocumentTemplateController::store()`'s single-step
create-with-version-1 shape exactly.** There is no separate "create
applicant, then create application" two-step flow; a new application
always starts `stage: applied`, `status: active`,
`ready_for_conversion: false`, set by the controller, never accepted
from request input.

**`ApplicationStage`'s transition guard is the same pattern as
`LifecycleProcessStatus`/`RecruitmentJobStatus`/`HrGeneratedDocumentStatus`
— a single `canTransitionTo()` source of truth, checked server-side on
every `PATCH .../stage` call, never inferred from which endpoint was
hit.** `applied → screening → interview → offer → hired`, with
`rejected`/`withdrawn` reachable from any non-terminal stage;
`hired`/`rejected`/`withdrawn` are terminal.

**Split permissions (your recommended option): `job_openings.*` and
`job_applications.*`, not one generic `recruitment.*` key.** Job
openings get the plain `view/create/update/delete` set. Applications
get that same set plus three narrow, separately-gated write actions —
`update_stage`, `add_note`, `mark_ready_for_conversion` — so a role
(HR Officer) can move the pipeline forward and add notes without ever
holding `.delete`, the same "narrow write actions, not folded into
`.update`" reasoning as `hr_generated_documents.submit`/`.approve`/
`.reject`. Grants: Tenant Admin/HR Director/HR Manager get everything;
HR Officer gets everything except `.delete`; Auditor gets `.view` only
on both resources; Line Manager and Employee get nothing this
checkpoint — no assigned-interviewer scoping model exists yet to base a
partial grant on, and a fake partial scope would be worse than no
access at all.

**Candidate-to-employee conversion is explicitly NOT built this
checkpoint — `ready_for_conversion` is a milestone flag only, gated by
its own `job_applications.mark_ready_for_conversion` permission (your
approved choice over folding it into `.update_stage`), and setting it
never creates an `Employee` row.** The frontend's Application Show page
renders a permanently-disabled "Convert to Employee (coming soon)"
button next to the real toggle, so the gap is visible rather than
silently absent. The future flow, when built, will need to resolve: a
real `employee_number`, optional `User` account linking, whether any
`recruitment_applicant` fields (email, phone) carry over as the
starting `Employee` record, whether the source application's notes
carry over as `EmployeeDocument`/audit context, and which lifecycle
process (onboarding) it should automatically kick off, if any — each of
those is its own trust boundary already established by an earlier
checkpoint (Employee creation, User↔Employee linking, Lifecycle
Foundation) and deserves its own explicit approval, not a
one-request bundling.

**Notes are internal-only, never logged verbatim.** `recruitment_application_notes.note`
and `recruitment_applications.cover_letter` are real candidate-authored
free text — same "audit metadata never contains full free-text content"
rule already established for HR document `rendered_content` and leave
rejection reasons (Checkpoint 34/14): `job_application_note.created`
and `job_application.updated` audit entries record that a note/cover
letter changed, never its text.

## Candidate-to-Employee Conversion Foundation (Checkpoint 40)

Turns the `ready_for_conversion` milestone flag (Checkpoint 39) into a
real, safe action, per your approved gap analysis. See
[`security.md`](security.md#candidate-to-employee-conversion-foundation-checkpoint-40)
for the full security model and
[`api.md`](api.md#candidate-to-employee-conversion) for the route
reference.

**Schema addition: three nullable columns on `recruitment_applications`
— `converted_employee_id` (FK → `employees`), `converted_at`,
`converted_by` (FK → `users`).** One additive migration, no changes to
any existing table. The application row itself is never deleted or
overwritten by conversion — these three columns are the *only* trace
conversion leaves on it; everything else (stage, cover letter, notes)
stays exactly as it was.

**Eligibility requires stage `hired` AND `ready_for_conversion: true`,
your explicit approved choice over the looser "either/or" reading of
the checkpoint brief.** A candidate merely flagged ready at the `offer`
stage cannot be converted until the stage itself is actually advanced
to `hired` — two independent signals, both required, checked twice
(once in `ConvertApplicationToEmployeeRequest::withValidator()`, once
again in the controller as defense-in-depth, mirroring how every
tenant-ownership check in this app is verified at both the FormRequest
and controller layer).

**One deliberately narrow permission: `job_applications.convert_to_employee`,
gated alone — not also `employees.create`, your explicit approved
choice.** The checkpoint brief asked for "one deliberate permission";
requiring a second, unrelated permission on top would contradict that
instruction, and every role that gets `.convert_to_employee` (Tenant
Admin, HR Director, HR Manager) already holds `employees.create`
in practice anyway. HR Officer — despite holding every other
recruitment write permission (`.update_stage`/`.add_note`/
`.mark_ready_for_conversion`) — does **not** get `.convert_to_employee`
by default: converting to an employee is a materially bigger,
harder-to-reverse action than any of those, per your explicit
approved mapping.

**Field mapping reuses `StoreEmployeeRequest`'s exact validation rules
— never a looser parallel rule set.** `ConvertApplicationToEmployeeRequest`
duplicates the same `employee_number`/`work_email` per-tenant uniqueness
checks and the same active-department/position/location existence
checks `StoreEmployeeRequest` already enforces for a normal employee
create. `first_name`/`last_name` come from the applicant directly (not
user-editable in this form — they're the candidate's own submitted
name). `department_id`/`position_id`/`location_id`/`employment_type`
pre-fill from the job opening when present, but the submitted value is
what's actually validated and persisted — the backend remains the
authority, the pre-fill is only a frontend convenience.

**`employment_type` is required on Employee but nullable on
RecruitmentJob — a known, documented gap, not a bug.** A job opening
with no `employment_type` set simply forces the HR user to pick one
manually in the conversion form; the same `required` rule
`StoreEmployeeRequest` already has is reused verbatim, no special-casing.

**`manager_employee_id` is never part of conversion — the same
"immutable-at-creation" rule every other employee-creation path in this
app already follows.** Assigning a manager stays the exclusive job of
`PATCH /employees/{id}/manager` (`AssignManagerRequest` +
`ManagerHierarchyService`'s cycle-detection); reimplementing that
validation inside the conversion action would duplicate a whole
subsystem for a field that can just as easily be set as an immediate
follow-up action in the existing Employee UI.

**Transactional, all-or-nothing.** `JobApplicationController::convertToEmployee()`
wraps the `Employee::create()` call and the application's
`converted_employee_id`/`converted_at`/`converted_by` update in a single
`DB::transaction()` — both succeed or both roll back together. A
uniqueness failure is actually caught earlier, at the FormRequest
validation layer (before the transaction ever opens), so in practice no
partial employee row or partially-converted application can ever exist;
the transaction is the belt-and-braces guarantee for anything that
might fail *inside* it in the future (e.g. a DB-level constraint this
app's own validation didn't anticipate).

**No automatic user account, role assignment, or onboarding start —
all three explicitly out of scope, per your instruction.** The
Application Show page's post-conversion state links to the new
employee's profile and to `/lifecycle/create?employeeId=...&type=onboarding`
(Option A, your approved choice) — reusing the existing Lifecycle
Create page's query-string pre-fill unchanged, zero new backend code,
rather than adding an "auto-start onboarding" checkbox that would need
its own review of *which* lifecycle type/tasks make sense by default.

**Audit logging: two entries, one per resource, neither ever containing
cover letter/note text.** `job_application.converted_to_employee`
(module `recruitment`, metadata: the new `converted_employee_id`) and
`employee.created_from_recruitment` (module `employees`, metadata:
`source_application_id`) — mirrors the "two audit entries, one per
resource touched" pattern Checkpoint 38's duplication already
established (`hr_document_template.duplicated` alongside the new
template's own creation trail).

### Future

Documented, not built: automatic `User` account creation and role
assignment at conversion time; an "start onboarding automatically"
checkbox (Option B, deliberately not chosen this checkpoint); real
offer-letter automation tying HR Documents (Checkpoint 34) to the offer
stage; a public candidate portal; bulk conversion; and applicant
dedupe/merge-by-email carrying through to conversion (currently, since
Checkpoint 39 never deduplicates applicants, two independent
applications from "the same" candidate could each be converted into two
separate employee rows — a real, documented limitation, not addressed
this checkpoint).

## Recruitment-to-Onboarding Handoff Foundation (Checkpoint 41)

Closes the gap Checkpoint 40 deliberately left open: converting a
candidate created an `Employee` but no path connected that employee
back to a real, tracked onboarding process — only a manual, pre-filled
link to the Lifecycle Create form. See
[`security.md`](security.md#recruitment-to-onboarding-handoff-foundation-checkpoint-41)
for the full security model and [`api.md`](api.md#recruitment-to-onboarding-handoff)
for the route reference.

**No schema change to `employee_lifecycle_processes` itself — one
additive nullable column on `recruitment_applications`,
`onboarding_process_id` (FK → `employee_lifecycle_processes`,
`nullOnDelete`).** The application row keeps accumulating trace columns
the same way `converted_employee_id`/`converted_at`/`converted_by` did
in Checkpoint 40 — never deleted or overwritten, additive history only.

**`JobApplicationController::startOnboarding()` takes no request body
at all.** `employee_id`, `type: onboarding`, and `status: draft` are
entirely derived from the application's own persisted
`converted_employee_id` — there is nothing in the request for a caller
to forge, unlike conversion's `ConvertApplicationToEmployeeRequest`,
which at least validates real form fields.

**Three preconditions, all checked server-side via `abort_unless`/
`abort_if`, none inferable from the frontend:**

1. `converted_employee_id !== null` — the application must already be
   converted.
2. `onboarding_process_id === null` — onboarding hasn't been started
   for *this application* before.
3. No existing `LifecycleProcess` for the converted employee with
   `type: onboarding` and a non-terminal status (`draft`/`in_progress`)
   — the converted employee doesn't already have an active onboarding
   process running, whether or not it originated from this same
   application. A prior `completed` or `cancelled` process does **not**
   block a new one, mirroring `LifecycleProcessStatus::isTerminal()`'s
   existing terminal/non-terminal split from Checkpoint 33 — reused
   verbatim rather than inventing a parallel notion of "still open."

**Permission: `lifecycle.create`, reused — not a new
`job_applications.start_onboarding`-style permission.** Starting an
onboarding process is fundamentally a lifecycle action (Checkpoint 33
already gates lifecycle-process creation behind exactly this
permission); recruitment is just one of the places that action can now
be triggered from. This surfaced a real, pre-existing gap: HR Director
held `job_applications.convert_to_employee` (Checkpoint 40) but zero
`lifecycle.*` permissions, so it could convert a candidate but never
start their onboarding. `RoleSeeder` now grants HR Director the
identical full lifecycle permission set (`lifecycle.view/create/
update/delete/assign_task/complete_task`) HR Manager already has —
found and fixed as part of this checkpoint's own review, not a
separate, deferred follow-up.

**Transactional.** Creating the `LifecycleProcess` row and setting the
application's `onboarding_process_id` happen inside one
`DB::transaction()` — both succeed or neither does, the same pattern
Checkpoint 40's conversion already established.

**Audit logging: two entries, one per resource touched** —
`job_application.onboarding_started` (module `recruitment`) and
`employee_lifecycle_process.created_from_recruitment` (module
`lifecycle`) — the identical shape Checkpoint 40 used for
`job_application.converted_to_employee`/`employee.created_from_recruitment`.

**Frontend**: `ApplicationShow.tsx`'s post-conversion panel replaces
Checkpoint 40's static "Start onboarding" link with a real button
(`PermissionGate`-wrapped on `lifecycle.create`) that calls the new
endpoint directly and then renders a link to the created process, or
its current status if one already exists — no more silent hand-off to
a separate page the user then has to fill in themselves.

### Future

Documented, not built: automatic task creation/templates on the newly
started process (the process is created bare, exactly as a manual
Lifecycle Create would leave it — **resolved next checkpoint, see
below**); notifications to the new employee or their manager that
onboarding has started; and automatic `User` account/role-assignment
at this same handoff point — the latter two remain Checkpoint 40's
original deferred scope, unchanged by this checkpoint.

## Onboarding & Offboarding Task Templates Foundation (Checkpoint 42)

Closes the "process starts bare" gap Checkpoint 41 flagged immediately
above. See
[`security.md`](security.md#onboarding--offboarding-task-templates-foundation-checkpoint-42)
for the full security model and
[`api.md`](api.md#onboarding--offboarding-task-templates) for the
route reference.

**A new table, `lifecycle_task_templates` — deliberately its own
table, not a column/flag on `employee_lifecycle_tasks`.** A template
needs to be edited or archived without ever touching a task that was
already generated from it; keeping them as two separate tables makes
that structurally true rather than something application code has to
enforce. Columns: `tenant_id`, `type` (reuses `LifecycleProcessType` —
onboarding/offboarding — rather than a new enum, since a template's
whole purpose is "which process type does this apply to"), `title`,
`description` (nullable), `due_in_days` (nullable, 0-365), `sort_order`
(default 0), plus the usual `created_by`/`updated_by`/soft-delete
columns every tenant-owned lookup model in this app has. Unique on
`(tenant_id, type, title)` — the same title can exist for onboarding
and offboarding independently, since they're really two separate lists
that happen to share a table.

**`LifecycleTaskTemplateApplier` is the one place template-copying
logic lives — called from both process-creation entry points, not
duplicated between them.** `LifecycleProcessController::store()` and
`JobApplicationController::startOnboarding()` (Checkpoint 41) both
create a `LifecycleProcess` and then call
`LifecycleTaskTemplateApplier::applyToProcess($process, $actorUserId)`,
which queries every non-archived template matching the process's own
`tenant_id` + `type`, ordered by `sort_order` then `title`, and creates
one real `LifecycleTask` per template — `title`/`description` copied
verbatim, `due_date` computed as `now()->addDays($template->due_in_days)`
when set (left `null` when the template has no due-day offset, not
defaulted to "today"), `status` always `pending`, and
`assigned_to_user_id` always `null` — a template has no way to know
who should get the task, so every generated task starts unassigned and
gets assigned the same way a manually created task already does
(`LifecycleTaskController::update()`).

**Generated tasks keep zero live link back to their template.** No
`source_template_id` column exists on `employee_lifecycle_tasks` —
once copied, a task is exactly as independent of its template as a
manually created one always was, the same "generate once, then
independent" posture HR Documents established (Checkpoint 34) for
rendered content vs. template content. This is a deliberate scope cut,
not an oversight — see "Future" below.

**Both process-creation endpoints now run inside a transaction.**
`LifecycleProcessController::store()` had none before this checkpoint
(a single-row `create()` didn't need one); now that it also creates N
task rows in the same request, the process and every template-derived
task succeed or fail together. `JobApplicationController::startOnboarding()`
already had a transaction (Checkpoint 41); the applier call was simply
added inside the existing closure.

**Permission: its own group, `lifecycle_task_templates.*`, not folded
into `lifecycle.*`.** Managing the template catalog (an admin
configuration concern) is kept distinct from working the
processes/tasks it feeds, mirroring the existing
`document_categories.*`/`documents.*` split. Granted to Tenant Admin
(blanket)/HR Manager/HR Director (full manage) and HR Officer
(view/create/update, no delete) in the identical shape each role
already holds `lifecycle.*` itself, and view-only to Auditor — see
`docs/security.md` for the full mapping.

**Nine starter templates seeded for the `uesl` demo tenant** (five
onboarding: welcome email, IT/equipment setup, buddy assignment,
new-hire paperwork, orientation; four offboarding: revoke access,
collect equipment, exit interview, final settlement), via
`DemoDataSeeder::seedLifecycleTaskTemplates()`, the same idempotent
`firstOrCreate` pattern `seedHrDocumentTemplates()` already
established.

### Future

Documented, not built: assigning a default assignee (e.g. "always
assign IT-setup tasks to the IT Support role") per template; a
`source_template_id` trace from a generated task back to the template
it came from; and duplicating templates in bulk. **Reordering tasks and
due-date reminders were closed by Checkpoint 45** — see below — though
that checkpoint added ordering/reminders to *tasks*, not to the
template catalog itself; bulk-reordering the templates in
`/settings/lifecycle-task-templates` is still not built.

## User Account Provisioning (Checkpoint 43)

Closes a gap older than the recruitment/lifecycle chain itself. See
[`security.md`](security.md#user-account-provisioning-checkpoint-43)
for the full security model and
[`api.md`](api.md#user-account-provisioning) for the route reference.

**`users.create` was reserved, not invented.** It was seeded back in
Checkpoint 23 alongside `users.view`/`users.deactivate`/`users.assign_role`
as part of a natural CRUD verb set, but no route ever used it — the
only way a `User` row could ever come into existence was `UserSeeder`
or `tinker`. `UserController::store()` is the first controller action
in this app to use it. No new permission was invented for this
checkpoint; the reserved one was simply wired up, the same "find and
use what was already seeded ahead of time" pattern Checkpoint 36 used
for `hr_document_templates.publish`.

**One endpoint does three things atomically: create, assign a role,
optionally link.** `POST /api/v1/users` runs inside a single
`DB::transaction()`: `User::query()->create(...)`, then
`$user->assignRole($role, $actor)` (reusing `HasPermissions::assignRole()`
unchanged — it independently re-checks platform-vs-tenant scope and
writes its own `role.assigned` audit log, the same layered-guard shape
`UserRoleController` already relies on), then, only if `employee_id`
was given, the identical `user_id`/`linked_at`/`linked_by` update
`EmployeeUserLinkController::store()` already performs. There is no
moment where the account exists unassigned or the employee is
momentarily still unlinked if a later step were to fail.

**`employee_id` is optional, and validated the same way linking an
*existing* user already is.** `StoreUserRequest` re-implements
`LinkEmployeeUserRequest`'s two employee-state checks (not already
linked, not terminated) against the employee resolved from the
`employee_id` *input* rather than a route-bound model, since this
request has no route parameter to read the employee from. Duplicated
logic, not shared, because the two requests validate against
structurally different sources.

**Deliberately a separate, explicit action — never automatic.** Your
approved scope choice: account creation is never triggered by
`JobApplicationController::convertToEmployee()` (Checkpoint 40) or
`::startOnboarding()` (Checkpoint 41) — both endpoints' own docblocks
already said "no user account... is created automatically" before this
checkpoint, and that sentence stays true after it. This mirrors
`EmployeeUserLinkController`'s own documented rule that linking an
existing user is always a deliberate, manual action, never a side
effect of something else.

**Password handling, updated by Checkpoint 46.** Originally, the caller
(an HR Manager or Tenant Admin) always set the account's real initial
password directly in the create form, confirmed by a second field
(`password_confirmation`, Laravel's `confirmed` rule) so a typo isn't
silently locked in — that path still exists (`send_invite: false`), but
as of Checkpoint 46 there's now a second, recommended path
(`send_invite: true`) that emails the new user a link to set their own
password instead — see
[Invite-Email Flow for New Accounts](#invite-email-flow-for-new-accounts-checkpoint-46)
below. Either way, the password is never returned in the API response
(`UserResource` already excluded it before this checkpoint) and never
written to the `user.created` audit log's `new_values` — only
`name`/`email`/`role_id`/`employee_id`.

**Frontend: a new Settings > Access > Users > Create page, plus a
shortcut from the Employee detail page.** `Settings/AccessUserCreate.tsx`
follows `Lifecycle/Create.tsx`'s own `?employeeId=` query-param
convention exactly — a pre-fill for the employee dropdown's initial
selection, never a trusted value; the actual `employee_id` sent to the
backend is always whatever the form currently holds. `EmployeesShow.tsx`
gained a new "User account" card: a "Create user account" link (gated
by `users.create`, shown only when `EmployeeResource`'s new
`linked_user` field is `null`) that pre-selects that employee on the
Create page, or the linked user's name (linking to their Settings >
Access > Users detail page, gated by `users.view`) once one exists.

**`EmployeeResource` gained `linked_user: {id, name} | null`** —
mirrors `UserResource`'s own `linked_employee` shape in reverse, same
"safe display summary, never the full related record" rule (no email,
status, or roles). Required eager-loading `Employee::user()` (an
existing relation, previously unused by `EmployeeController`) alongside
`department`/`location`/`position` on every action that already loads
those.

### Future

Documented, not built: ~~a real password-reset/invite-email flow~~ —
done in Checkpoint 46. Self-service password change (an *authenticated*
user changing their own known password, distinct from both the
forgot-password flow and the invite flow), MFA, bulk user import, and a
"resend invite" action for an account whose invite link expired before
they used it. None of these were part of this checkpoint's scope.

## Password Reset (Checkpoint 44)

Closes the "biggest remaining gap" Checkpoint 43 itself flagged. See
[`security.md`](security.md#password-reset-checkpoint-44) for the full
security model and [`api.md`](api.md#password-reset) for the route
reference.

**Four new guest-only routes, alongside `login`/`logout` in
`routes/auth.php` — not under `/api/v1`.** `GET`/`POST /forgot-password`
and `GET`/`POST /reset-password` follow the exact route-naming
convention Laravel's own password-reset broker expects
(`password.request`/`password.email`/`password.reset`/`password.store`),
since `Illuminate\Auth\Notifications\ResetPassword`'s default URL
builder calls `route('password.reset', ...)` internally. Living outside
`/api/v1` matches this app's existing boundary: `/login`/`/logout` are
the only other guest-reachable, non-tenant-scoped auth endpoints, and
they aren't under that prefix either.

**No `tenant.matches` on any of the four — correctly, not an
oversight.** That middleware only ever acts on an authenticated
`$request->user()` (`EnsureTenantMatchesAuthenticatedUser` returns
early when there is none); none of these routes ever have one. Tenant
boundary enforcement lives entirely in `ForgotPasswordRequest`/
`ResetPasswordRequest` instead — see `docs/security.md` for exactly how.
`route:audit-tenant-scoping` already excludes `login`/`logout` by
prefix and only checks routes that actually carry `auth` middleware in
the first place, so these new routes need no exclusion-list entry — the
audit correctly never flags them (0 additions to "missing" either way).

**`ResetPassword::createUrlUsing()` in `AppServiceProvider::boot()` is
the one place a tenant-aware reset URL gets built.** Given a `User`,
it resolves `{subdomain}.{base_domain}` from `$user->tenant` (falling
back to the base domain for `is_platform_admin`/no-tenant users), reusing
the identical subdomain-composition logic `ResolveTenant` middleware
already uses in reverse (there subdomain → tenant; here tenant →
subdomain). The scheme comes from `config('app.url')`, not the current
request — this runs at `POST /forgot-password` time, but there's no
principled reason the *notified* user's own link should depend on
whichever scheme the sender happened to use.

**`ForgotPasswordRequest`/`ResetPasswordRequest` duplicate, rather than
share, the tenant-eligibility check.** The same three-line
platform-admin-vs-tenant ternary now exists in four places
(`LoginRequest::isAllowedToLoginHere()`, `EnsureTenantMatchesAuthenticatedUser`,
and these two new requests) — each validates a different kind of input
(a login attempt, an authenticated session, an emailed token, a bare
email address with no session at all), so this follows the same
"duplicated, not prematurely shared" precedent `StoreUserRequest`
already set for its own employee-state check in Checkpoint 43, rather
than introducing a new trait/helper four call sites deep.

**No queued job — the first email this app sends, and it sends
synchronously.** Nothing in this codebase implements `ShouldQueue` yet
(see `docs/deployment.md`); rather than make this checkpoint the one
that introduces queueing, the notification is dispatched synchronously
inside the same request, exactly as `MAIL_MAILER=log`'s existing
local/demo posture already implied it eventually would.

**A new shared Inertia prop, `status`.** `HandleInertiaRequests` now
shares `session('status')` — the first page-to-page, one-time success
message anywhere in this frontend (every other success/error message in
this app is either an Inertia validation-error redirect, like
`/login`, or local component state driven by a direct axios `api` call,
like every Settings/Employees page). `ForgotPassword.tsx` and
`Login.tsx` (after a successful reset redirects there) both render it
as a green banner when present.

### Future

~~A real invite-email flow reusing this checkpoint's tenant-aware URL
approach~~ — done in Checkpoint 46 (which reuses this checkpoint's
`/reset-password/{token}` page directly, rather than the URL-building
approach specifically — see below). Queuing the reset email once a real
queue worker exists, a post-reset confirmation email, and IP-based rate
limiting on top of the existing per-email throttle. None of these were
part of this checkpoint's scope.

## Lifecycle Task Ordering & Reminders (Checkpoint 45)

Closes two gaps Checkpoint 33/42 both flagged as future work: tasks had
no ordering beyond insertion order, and nothing ever reminded anyone a
task was due or overdue. See
[`security.md`](security.md#lifecycle-task-ordering--reminders-checkpoint-45)
for the full security model and
[`api.md`](api.md#lifecycle-task-ordering--reminders) for the route
reference.

**`sort_order` on `employee_lifecycle_tasks` — a plain integer column,
not a dependency graph.** The two words this feature was scoped under
("ordering," "reminders") were ambiguous between "manual display order"
and "task B is blocked until task A completes" — this checkpoint
implements the former only; there is no blocking/prerequisite concept
anywhere in `LifecycleTaskStatus` or the controller, and completing/
skipping a task never checks any other task's state. `LifecycleProcess::tasks()`
now returns `->orderBy('sort_order')->orderBy('created_at')` — the
ordering lives in the relation itself, one place, rather than being
repeated at every call site (`show()`, `store()`'s `->load('tasks')`,
the reorder endpoint's own response).

**A generated task copies its template's `sort_order` at generation
time; a manually-added task is appended to the end.**
`LifecycleTaskTemplateApplier` copies `sort_order` alongside title/
description/due-date — the same "generate once, then independent"
posture as those fields (editing a template's order later never
reaches back into tasks already generated from it).
`LifecycleTaskController::store()` computes
`($process->tasks()->max('sort_order') ?? -1) + 1` for a manually added
task rather than defaulting to `0`, which would otherwise jump a new
task ahead of every already-ordered one.

**`POST /lifecycle-processes/{id}/tasks/reorder` replaces the whole
order in one call — it does not move a single task.**
`ReorderLifecycleTasksRequest` requires `task_ids` to be exactly the
process's current task ID set (same count, no foreign IDs, no
duplicates, no omissions) — a partial reorder request is rejected
outright, so there's never a question of where an unlisted task should
land. Gated by `lifecycle.update`, the same permission editing the
process itself already requires — not a new, narrower key, the same
"reuse an existing write permission for a sub-action" reasoning this
app has applied repeatedly (e.g. `hr_generated_documents.submit`/
`.approve`/`.reject` are the exception that proves the rule: those got
their own keys specifically *because* HR Officer needed to submit
without ever approving; no comparable split exists here — every role
holding `lifecycle.update` is already fully trusted with this
resource).

**The Lifecycle process page supports native HTML5 drag-and-drop —
deliberately no new npm dependency.** `draggable`/`onDragStart`/
`onDragOver`/`onDrop` on each task row reorders the in-memory list the
instant a row is dropped (optimistic update), then persists the full
new order via the reorder endpoint; a failed save falls back to
re-fetching the process from the server. This is the first place in
this frontend that updates local state before its server round-trip
completes — every other mutation here waits for the response first —
because a reorder needs to feel instant, and reordering (unlike every
other action on this page) has no failure mode a user would find
surprising enough to need to see mid-drag.

**`lifecycle:send-task-digest` is the app's first scheduled task.**
Registered via `bootstrap/app.php`'s new `->withSchedule()` closure —
every checkpoint before this one left it absent entirely (see
`docs/deployment.md` §6, previously "no scheduler infrastructure
exists; revisit once a genuinely scheduled task is actually built" —
this is that moment). Runs daily at 07:00 server time (no per-tenant
timezone concept exists yet, so every tenant's digest fires at the same
wall-clock moment). For each active tenant, it binds that tenant into
the container (`app()->instance(Tenant::class, $tenant)`) exactly the
way `ResolveTenant` middleware does per-request — outside an HTTP
request there is no subdomain to resolve one from, so the command must
do the equivalent itself before `LifecycleTask::query()`'s
`BelongsToTenant` global scope will filter correctly.

**One email per assignee per run, never one per task.** Every pending/
in-progress task that's overdue or due within `DUE_SOON_WITHIN_DAYS`
(3) days, with a still-active assignee, is grouped by
`assigned_to_user_id` before sending — `App\Notifications\LifecycleTaskDigestNotification`
lists every one of that assignee's qualifying tasks in a single email,
split into "overdue" and "due soon" sections. Tasks with no assignee
are silently skipped (there is no one to remind), and an assignee who
was active when a task was assigned to them but has since been
deactivated is re-checked and skipped at send time — assignment-time
validation only guarantees "was active then," not "is active now."

**Deliberately not queued (`ShouldQueue`).** `QUEUE_CONNECTION=database`
has been configured since the very first checkpoint but nothing has
ever actually been queued (see `docs/deployment.md` §6). Queuing this
notification would mean standing up a *second* new always-on
infrastructure piece (a persistent `queue:work`/supervisor process) in
the same checkpoint that introduces the *first* (the scheduler's cron
entry) — sending synchronously from within the already-scheduled
command is still a real email, without that second dependency. This is
a deliberately deferred follow-up, not an oversight — see "Future"
below.

**One audit log entry per tenant per run, not per recipient.**
`lifecycle_task_digest.sent` records the recipient count and total task
count for that tenant, `actor_type: 'system'` (no acting user — this is
a scheduled, not user-triggered, action), and is only written when at
least one email was actually sent (a tenant with nothing due writes
nothing, the same "only log meaningful outcomes" pattern
`LifecycleTaskController::update()` already applies via its
`$changes !== []` guard).

### Future

Per-tenant timezone-aware send times (every tenant currently gets the
same wall-clock 07:00 regardless of where they operate); an in-app
notification center as an alternative/addition to email; digest
suppression or snooze (an assignee currently gets the identical email
every single day a task remains overdue, with no way to acknowledge
"seen" short of completing/skipping the task itself); queuing the
digest notification once a real queue worker process exists in
production; and bulk-reordering the `lifecycle_task_templates` catalog
itself (this checkpoint only reordered generated tasks, not the
templates that generate them). None of these were part of this
checkpoint's scope.

## Invite-Email Flow for New Accounts (Checkpoint 46)

Closes the gap repeatedly flagged as "the single biggest remaining
gap" since Checkpoint 43: `POST /api/v1/users` no longer *always*
requires the caller to set (and share out of band) the new account's
real initial password. See
[`security.md`](security.md#invite-email-flow-for-new-accounts-checkpoint-46)
for the full security model and
[`api.md`](api.md#invite-email-flow-for-new-accounts) for the route
reference.

**`send_invite` is now the required field deciding how the account gets
its password — your approved scope choice was "admin's choice," not
"invite-only."** `StoreUserRequest::rules()`'s `password` field changed
from unconditionally `required` to `Rule::requiredIf(fn () => !
$this->boolean('send_invite'))` — required only on the `send_invite:
false` path (Checkpoint 43's original behavior, unchanged). A
`withValidator` closure rejects `send_invite: true` submitted alongside
a non-empty `password` outright (422) rather than silently preferring
one over the other — a caller sending both almost certainly meant only
one of them.

**`UserController::store()`'s two paths, after the same transaction
either way.** `send_invite: false` is byte-for-byte Checkpoint 43's
original behavior — the submitted password is set directly, inside the
transaction, alongside role assignment and the optional employee link.
`send_invite: true` creates the account with `Str::random(64)` as its
password instead (an unusable value nobody knows — cast through the
same `'password' => 'hashed'` Eloquent cast every other password on
this model already uses, so login is impossible until it's actually
replaced). Once the transaction commits, `Password::createToken($user)`
generates a real password-reset token and a new
`App\Notifications\UserInvited` is sent — deliberately outside the
transaction, the same reasoning Checkpoint 44's
`ForgotPasswordRequest::sendResetLinkIfEligible()` already established
for its own `Password::sendResetLink()` call: a mail-send failure must
never roll back an otherwise-successful account creation.

**`UserInvited` reuses Checkpoint 44's `/reset-password/{token}` page
directly — no new route, page, or token table.** "Set a password given
a valid token" is identical whether it's a genuine forgot-password
request or a brand-new account's first-ever password; the only thing
that needed to differ is the *wording*. This is why `UserInvited` is
its own `Notification` class rather than a reuse/subclass of
`Illuminate\Auth\Notifications\ResetPassword` — that class's default
"Reset Password" subject would be a confusing thing to send someone who
never had a password to reset. Getting a token for `UserInvited`
required calling `Password::createToken($user)` directly rather than
`Password::sendResetLink()` — the latter always sends the built-in
`ResetPassword` notification internally
(`CanResetPassword::sendPasswordResetNotification()` is hardcoded to
it), which would defeat the point of a differently-worded notification
existing at all.

**`UserInvited`'s tenant-aware URL logic is now duplicated a third
time, not extracted into a shared helper.** The same
`{subdomain}.{base_domain}`-plus-scheme construction now appears in
`AppServiceProvider::boot()`'s `ResetPassword::createUrlUsing()`
closure (Checkpoint 44), `SendLifecycleTaskDigest::tenantLifecycleUrl()`
(Checkpoint 45), and here. Deliberately still duplicated rather than
shared, the same "duplicate until a real need for a helper appears"
posture already applied to the tenant-eligibility check duplicated
across `LoginRequest`/`ForgotPasswordRequest`/`ResetPasswordRequest` —
worth revisiting if a fourth call site ever needs the identical logic.

**Two audit log entries, not one, mirroring Checkpoint 45's
"tasks_applied_from_templates" precedent.** `user.created` is written
for both paths (with a new `metadata.invited` boolean); `user.invited`
is written as a second, independent entry only on the `send_invite:
true` path, right after the notification is actually sent — a distinct
real-world event (an email went out) gets its own log entry, the same
"one entry per resource touched" convention already established for
template application (Checkpoint 42) and the recruitment-to-onboarding
handoff (Checkpoint 41).

**Frontend: a "Password setup" select on the Create User form,
replacing the always-visible password fields.** Defaults to "Send
invite email" — switching to "Set password now" reveals the
password/confirm-password fields exactly as they existed before this
checkpoint. Only one path's fields are ever included in the submitted
payload; the other is omitted entirely, not sent as empty strings.

### Future

Self-service password change for an already-authenticated user (a
distinct feature from both the forgot-password flow and this one — not
addressed by either); MFA; bulk/CSV user import; and a "resend invite"
action for an account whose invite link expired before they used it —
the only way to get a fresh link today is a real forgot-password
request from the login page, which works (the account exists and has a
real, if unusable, password hash) but isn't a purpose-built "resend"
affordance. None of these were part of this checkpoint's scope.

## Module Registry & Branding Foundation (Checkpoint 47)

The first checkpoint built explicitly against
[`docs/platform-vision.md`](platform-vision.md)'s "platform kernel"
direction — `TenantModule` is meant to be the durable shape every
future module registers against, not a one-off enum for this
checkpoint's six modules alone.

**`App\Enums\TenantModule` is the backend module registry — a
backed enum, never free text from the frontend.** Cases split into
`core()` (never toggleable: `employees`, `settings`, `users_access`,
`audit_logs`, `dashboard`, `manager_hierarchy`, `password_reset`,
`account_invites`) and `toggleable()` (`recruitment`, `lifecycle`,
`leave`, `documents`, `policies`, `hr_documents`). Each case carries
`label()`, `description()`, `isToggleable()`, `routeGroupPrefixes()`
(the URL prefixes that belong to it, for both `routes/api.php` and
`routes/web.php`), `additionalGatedUris()` (exact-URI exceptions for
routes that don't fit a clean prefix — see below), and
`relatedModules()` (purely informational cross-references, e.g.
Recruitment↔Lifecycle). The enum's case values *are* the module keys
persisted to `tenant_modules.module_key` and returned over the API —
per `docs/platform-vision.md`, these values are meant to stay stable
indefinitely, since a future entitlement/subscription layer will key
off the same strings.

**A route can belong to more than one module.** The
`start-onboarding` endpoint is reachable only when *both* Recruitment
and Lifecycle are enabled — it's gated by two separate `module:{key}`
middleware entries, one per module, rather than forcing a single
"owning module" per route. `routeGroupPrefixes()`/
`additionalGatedUris()` exist precisely so a module's registry entry
can claim a route without requiring every route to have exactly one
owner.

**`tenant_modules`: explicit rows are the steady state, not a lazy
cache.** One row per tenant per toggleable module
(`unique(tenant_id, module_key)`), defaulting `enabled = true`.
Existing tenants are backfilled by the migration itself; new tenants
get rows via `TenantModuleService::provisionDefaults()`, called both
from `Tenant::booted()`'s `created` hook (for any real provisioning
flow) and explicitly from `TenantSeeder` (since `DatabaseSeeder`'s
`WithoutModelEvents` trait suppresses the model event during seeding —
see "Errors and fixes" precedent for `User`/`Role` guards under the
same trait). `TenantModuleService::isEnabled()`'s "missing row ⇒
enabled" behavior is a defensive fallback only, never the intended
mechanism — a genuinely missing row should not normally occur once
provisioning runs everywhere it needs to.

**`EnsureModuleEnabled` (`module:{key}`) blocks the backend route
itself, not just the UI.** Middleware order is
`auth → tenant.matches → module:{key} → permission:{key}` — module
gating happens *before* permission checks, so a disabled module never
even reveals whether the caller would otherwise have had permission.
A disabled module returns exactly
`{"message": "This module is not enabled for your organisation.", "reason": "module_disabled"}`
with HTTP 403 — a stable, machine-checkable reason string, not just a
generic 403. Disabling a module **never** deletes, archives, or
mutates its business data — `TenantModuleService::setEnabled()` only
ever writes to the `tenant_modules` row itself.

**Unknown or core module keys are rejected with 422, not a route-model-binding 404.**
`TenantModuleController::update(string $moduleKey)` deliberately does
not type-hint `$moduleKey` as `TenantModule` — Laravel's implicit
enum route-model binding would 404 on a bad value, which reads to a
caller as "route doesn't exist" rather than "this key is invalid."
Instead the controller resolves it manually
(`TenantModule::tryFrom()`) and asserts `isToggleable()`, returning a
clear 422 either way.

**`route:audit-module-gates` is a permanent regression guard, not a
one-time check.** Mirrors the existing `route:audit-tenant-scoping`
command exactly: iterates every toggleable module's
`routeGroupPrefixes()`/`additionalGatedUris()`, asserts the matching
route carries its `module:{key}` middleware, and fails the build if
not. Wired into CI immediately after the tenant-scoping audit. A new
route added under a toggleable module's prefix without its gate now
fails CI the same way a route missing `tenant.matches` already does.

**`tenant_branding` is a separate table, not columns on `tenants`.**
`id, tenant_id (unique FK), logo_path, logo_original_filename,
primary_color, secondary_color, created_by, updated_by, timestamps`.
Kept separate from `tenants` because branding is optional
per-tenant configuration with its own lifecycle (a tenant can exist
with zero branding rows — `TenantBrandingController` uses
`firstOrNew` to synthesize an empty one on read), whereas `tenants`
itself is core platform identity data.

**Logo storage: tenant-scoped and unguessable, never the internal
path in API responses.** Uploaded to the `public` disk at
`tenant-branding/{tenant_id}/{Str::random(40)}.{ext}` — the tenant's
ULID (never a sequential integer) plus a 40-character random
filename, so a path can't be enumerated or guessed even by another
authenticated user of the same tenant. `TenantBranding::logoUrl()` is
the only way the frontend ever learns about the file — it resolves
through `Storage::disk('public')->url()`; `logo_path` itself is never
serialized by `TenantBrandingResource`. Replacing a logo deletes the
previous file; removing it nulls both `logo_path` and
`logo_original_filename` and deletes the file. Validation
(`UploadTenantLogoRequest`) accepts only PNG/JPG/JPEG (no SVG — ruled
out entirely for the MVP, closing off the classic SVG-with-embedded-
script upload vector), enforces a max file size and max pixel
dimensions via Laravel's `dimensions` rule (requires GD, confirmed
present in this environment).

**Colors: strict 6-digit hex only, no free-form CSS.**
`UpdateTenantBrandingRequest` validates `primary_color`/
`secondary_color` against `^#[0-9a-fA-F]{6}$` — no named colors, no
`rgb()`/`rgba()`, no shorthand 3-digit hex, and critically no path
for injecting arbitrary CSS/HTML/JS through a "color" field. There is
no custom-CSS, custom-HTML, or theme-builder surface anywhere in this
checkpoint; branding is exactly display name + logo + two colors.

**Shared Inertia props expose only what the frontend needs to render,
never internal IDs or actors.** `HandleInertiaRequests` adds
`tenant.modules` (a plain `module_key => bool` map, from
`TenantModuleService::enabledMap()`) and `tenant.branding`
(`logo_url`, `primary_color`, `secondary_color` only) to every
tenant-scoped request. Neither includes `tenant_modules`/
`tenant_branding` row IDs, `enabled_by`/`disabled_by`/`created_by`/
`updated_by`, or the internal `logo_path`. `Sidebar.tsx` hides a
module's nav entry when `modules[key] === false`, failing **open**
(visible) if the key is ever absent from the map — the backend route
gate is what actually enforces access; the sidebar is a convenience,
never the boundary. `ApplicationShow.tsx`'s "Start Onboarding" affordance
is hidden the same way when `tenant.modules.lifecycle === false`, so
the recruitment-to-lifecycle handoff introduced in Checkpoint 41
disappears from the UI exactly when the backend would reject it
anyway.

**Platform Super Admin cannot use a disabled module as a backdoor —
verified, not assumed.** `EnsureTenantMatchesAuthenticatedUser`
(`tenant.matches`) already rejects a platform admin on any tenant
subdomain, and it runs *before* `module:{key}` in the middleware
stack — a platform admin never reaches the module gate on a tenant
route at all. `tests/Feature/Tenant/TenantModuleApiTest.php` proves
this directly: one test asserts a platform admin is blocked outright
from every module route, and a second (
`test_platform_super_admin_cannot_bypass_a_disabled_module_via_tenant_routes`)
asserts the 403 response body never contains `module_disabled` for a
platform-admin request — proving `tenant.matches`, not the module
gate, is what's actually stopping them. No support-access mode exists
yet; if one is ever built, it must be explicit, time-boxed, and
audited — not an incidental side effect of module gating.

### Future

Per `docs/platform-vision.md`: an entitlement layer separate from
enablement (`tenant_module_entitlements`, package plans, trial/beta/
expired modules) sitting in front of the enablement layer this
checkpoint built; a real Platform-Super-Admin support-access mode
(explicit, time-boxed, audited); safe warning-count metadata beyond
the four modules currently covered (Documents/Policies were skipped —
no cheap, safe aggregate count was obvious without risking an
expensive or leaky query); a future implementation-engineer role;
configuration export/import; a tenant readiness checklist; a
configuration lock and configuration-history UI; login/email-page
branding (this checkpoint only brands the in-app UI); and
tenant-scoped caching for `enabledMap()`/branding if this becomes a
hot path (currently a plain per-request query, consistent with this
app's existing "no permission caching yet" posture).

## Custom Fields Foundation (Checkpoint 48)

The first checkpoint against
[`docs/platform-vision.md`](platform-vision.md)'s "build every major
capability once, then let modules reuse it" principle applied outside
the module-registry itself — the goal is a value-storage shape every
future workflow-condition, custom-report, dashboard, and AI-filter
engine can query the same way, regardless of which entity a field
belongs to.

**Why `recruitment_applicant` is the sole MVP entity, and why
`employees` is deferred.** `employees` is the most sensitive,
highest-blast-radius table in the app (compensation/medical/
disciplinary data is already deliberately deferred elsewhere) — it is
the worst place to debut a brand-new, unproven storage/validation/
audit engine. `RecruitmentApplicant` (`app/Models/RecruitmentApplicant.php`)
is a plain, no-workflow, no-compensation record, the newest module
(Checkpoint 39), already tightly permissioned, with zero
manager-hierarchy visibility rules to interact with — the lowest
blast radius available while still being a genuinely useful target.
Roadmap: `job_applications` next (near-zero engine work — a new
`CustomFieldEntity` case plus a permission mapping), then
`lifecycle_processes`/`leave_requests`, `employees` last, deliberately,
once the engine has field experience elsewhere.

**Storage: an EAV-leaning hybrid, not JSONB on the parent entity, and
not one JSON blob per entity.** Four tables:
`custom_field_definitions` (one row per field, relational — entity
type, key, label, type, required, default, sensitivity, sort order,
status), `custom_field_options` and `custom_field_validation_rules`
(children of a definition), and `custom_field_values` (one row per
`(tenant, entity_type, entity_id, definition)`, with typed nullable
columns — `value_text`/`value_number`/`value_date`/`value_boolean`,
plus `value_json` used *only* for `multi_select`'s selected option
keys, the one deliberate JSON usage in an otherwise fully relational
design). Rejected: JSONB directly on `recruitment_applicants` (no
generic cross-entity query mechanism for future reports/conditions,
harder per-field audit diffing, no structural guarantee against key
drift) and a single JSONB blob per entity in a shared table (better
than per-entity JSONB, but still not a clean per-field audit
diff, and still requires JSON-path queries for cross-entity filtering
instead of one indexed join). The one-row-per-field EAV shape is what
lets a future generic report/workflow-condition/AI-filter engine be
built once and reused by every entity — the actual test of whether
this design honors the "build once" principle is whether adding entity
#2 (`job_applications`) ever requires touching this storage engine at
all.

**`CustomFieldEntity` is the backend registry — a backed enum, never
free text**, mirroring `TenantModule`'s exact pattern. One case today
(`RecruitmentApplicant`), with `modelClass()`, `valueViewPermission()`/
`valueUpdatePermission()` (see permission model below), and
`reservedFieldKeys()`. `CustomFieldDefinitionController` resolves
`{entityType}` via `tryFrom()`, 422 on unknown — never a
route-model-binding 404, same non-defensive-enum-route-param
technique as `TenantModuleController`.

**Field keys are immutable; labels are not.** `field_key` is
`fillable` only inside `CustomFieldDefinitionService::create()` —
`UpdateCustomFieldDefinitionRequest` omits it from its rules entirely
(not merely ignored), so a resent value can't even look like it was
considered. This matters because field keys will later be referenced
by forms, workflow conditions, approval rules, reports, dashboards, AI
filters, API integrations, and configuration export/import — a rename
feature, if ever built, needs its own separate, audited design once
something is actually referencing keys by then.

**`field_type` can only change while the field has no stored values.**
`CustomFieldDefinitionService::update()` checks
`$definition->values()->exists()` before allowing a type change,
returning 422 otherwise — a type change on a field with data could
corrupt interpretation of existing values, and a safe migration path
(re-validating/converting existing values) is explicitly deferred as
its own future design, not built here.

**Reserved keys and format enforcement live in the service, not just
the FormRequest.** `CustomFieldDefinitionService`'s
`FIELD_KEY_PATTERN` (`^[a-z][a-z0-9_]{0,59}$`) and
`CustomFieldEntity::reservedFieldKeys()` (per-entity real columns —
`first_name`/`email`/`status`/`stage`/etc. — plus a shared dangerous-name
set — `password`/`token`/`role`/`is_platform_admin`/etc.) are both
checked before a definition is ever created, alongside an explicit
`assertFieldKeyUnique()` pre-check that turns what would otherwise be
a raw unique-constraint violation into a clean 422 (the database's
`unique(tenant_id, entity_type, field_key)` index is still the real
guarantee — this is a friendlier first check, not a replacement).

**A hard cap: `MAX_FIELDS_PER_TENANT_ENTITY = 50`.** Prevents
unbounded field sprawl from degrading the entity's own list/show
endpoints and any future forms/reports — a flat constant for MVP,
explicitly designed to become package/entitlement-dependent later
(see `docs/platform-vision.md`) without changing where the check
lives.

**Option keys are immutable once created; disabling one preserves
history.** `CustomFieldDefinitionService::syncOptions()` is an
upsert-by-`option_key` — a request payload entry with a matching
existing key updates label/sort_order/status in place, a new key
creates a row; no option is ever hard-deleted. A disabled option's
prior stored values stay exactly as they are and continue to display
on read (`CustomFieldValueValidator::validate()`'s
`enforceActiveOptions` parameter is only `true` on the write path,
never the read path), but a new write can no longer select it —
`CustomFieldValueService::getActiveValuesFor()` returns whatever is
stored regardless of the option's current status, while
`setValuesFor()` re-validates against currently-active options only.

**Required fields are enforced on new writes only, never
retroactively.** `is_required` is checked by
`CustomFieldValueValidator::validate()` at the moment a value is
submitted — a field made required after applicants already exist
never invalidates their pre-existing (absent) values; nothing
re-validates existing rows in the background. Concretely: submitting
a `custom_field_values` payload that includes a required field with an
empty value is rejected, but omitting that field entirely from an
unrelated update (e.g. only changing `cover_letter`) is unaffected —
sparse/partial-update semantics throughout, matching the codebase's
existing `sometimes`-validation conventions rather than forcing every
edit to resupply the entire field set.

**Sensitivity classification affects audit masking only — not read
access — in this checkpoint.** `CustomFieldSensitivity` (`normal` |
`sensitive` | `confidential` | `restricted`) exists on every
definition now so a future field-level-visibility checkpoint can add
a read-time permission filter without a schema change, but MVP does
not build that filter — anyone who can already view the parent entity
sees all of its active custom field values regardless of
classification. Documented explicitly to avoid a false security
assumption (see `docs/security.md`).

**Classification-aware audit masking, not name-pattern masking.**
`AuditValueSanitizer` (Checkpoint 24) masks by *field name substring*
— it can't know that a tenant-defined field like `visa_status` is
sensitive, since the name is arbitrary. `CustomFieldAuditEvents::valueUpdated()`
(`app/Services/CustomFields/CustomFieldAuditEvents.php`) checks the
definition's own `sensitivity->requiresAuditMasking()` and substitutes
`***MASKED***` for old/new values before ever calling `AuditLogger`,
for every classification except `normal` — the one place this
checkpoint had to build a second masking mechanism because the
existing one structurally couldn't cover it.

**Values are gated by the owning entity's own permission — no second
value-permission axis.** `custom_fields.view`/`.manage` control
*definitions* only (create/update/enable-disable a field, add/remove
options) — reading or writing a `RecruitmentApplicant`'s values
piggybacks entirely on `job_applications.view`/`.update`
(`CustomFieldEntity::valueViewPermission()`/`valueUpdatePermission()`),
the same permissions that already gate the parent `JobApplicationController`.
No new top-level values API exists — `custom_field_values` is just
one more field on the existing `PATCH /api/v1/job-applications/{id}`
request and `GET .../{id}` response, avoiding a second, independently-
permissioned surface that could become a bypass.

**Every value write re-verifies tenant/entity ownership independently
of the controller** — defense in depth, not a replacement.
`CustomFieldValueService::setValuesFor()`/`getActiveValuesFor()` both
filter `activeDefinitions()` by the caller's own `tenant_id` first;
a field_key belonging to a different tenant's definition is
indistinguishable from an unknown key (422), never a silent
cross-tenant read/write.

**A latent bug found and fixed while wiring this checkpoint's routes:
`route:audit-module-gates` had been checking web.php pages only.**
`routes/api.php` wraps every route in `Route::prefix('api/v1')`, so an
API route's registered URI is `api/v1/job-openings`, not
`job-openings` — `AuditModuleRouteGates::belongsToModule()`'s prefix
matching never accounted for this, so since Checkpoint 47 the command
had been silently skipping essentially every `api/v1/*` route (the
actual `module:{key}` middleware was still correctly applied at
registration time in `routes/api.php` throughout — proven by the live
smoke test in Checkpoint 47 — this was purely a gap in the audit
command's own coverage, not a real access-control hole). Fixed by
stripping a leading `api/v1/` before matching; the checked-route count
went from 45 to a real 134, still 0 missing. A new test
(`AuditModuleRouteGatesCommandTest::test_api_routes_are_actually_checked_not_silently_skipped`)
asserts this count stays meaningfully non-zero going forward.

**A second bug found live, after the checkpoint's own tests had
already passed and CI was green: definition create/update wasn't
transactional.** `CustomFieldDefinitionService::create()`/`update()`
inserted or mutated the `custom_field_definitions` row before running
option/default-value validation, with neither method wrapped in
`DB::transaction()`. A request that failed validation (an invalid
default value, an invalid option key) correctly returned `422` — but
the definition row had already been written and remained fully
`active` in the database, indistinguishable from a real field.
Confirmed live via `php artisan tinker` during the Checkpoint 48
smoke test (commit `9865ecc`). Fixed by wrapping the entire body of
both methods in `DB::transaction()`, with `custom_field.created`/
`.updated` audit events now fired only after a successful commit —
see `docs/testing.md` for the full writeup and the general lesson for
configuration-writing services elsewhere in this app.

### Future

Per `docs/platform-vision.md`: field-level visibility/read permissions
keyed on `sensitivity` (a real permission filter, not just audit
masking); a form/page designer and custom-forms builder consuming
these definitions; workflow conditions, approval rules, custom
reports, dashboards, and AI-assistant filters querying
`custom_field_values` generically across entities; configuration
export/import for definitions/options/rules (already structurally
ready — plain relational rows, stable `field_key`/`option_key`
identifiers, no raw internal IDs as the external contract); and a
future package/entitlement layer controlling field counts, which
entities support custom fields, and advanced field types — the
current 50-field cap and single-entity MVP are deliberately simple
placeholders for that later control, not the intended permanent
shape.

## Custom Fields for Job Applications (Checkpoint 49)

The proof point for whether Checkpoint 48's engine actually honors
`docs/platform-vision.md`'s "build once, reuse everywhere" principle:
adding a second entity required **zero** changes to any migration,
`CustomFieldDefinitionService`, `CustomFieldValueService`,
`CustomFieldValueValidator`, or `CustomFieldAuditEvents` — only a new
`CustomFieldEntity::JobApplication` case and this entity's own
controller/resource/frontend wiring, exactly mirroring
`RecruitmentApplicant`'s.

**`job_application` maps to `App\Models\RecruitmentApplication`, not
a second look at `RecruitmentApplicant`.** These are two different
models: `RecruitmentApplicant` is the candidate's identity
(first_name/email/phone/source, Checkpoint 48's target);
`RecruitmentApplication` is the pipeline record itself (stage,
status, cover_letter, ready_for_conversion,
converted_employee_id/converted_at/converted_by,
onboarding_process_id). The `job_applications.*` permission category
and `job-applications` routes have always governed
`RecruitmentApplication` — this checkpoint's entity target follows
that existing naming, not a new one.

**Two deliberately separate payload keys — never one merged object.**
`PATCH /api/v1/job-applications/{id}` now accepts both
`custom_field_values` (unchanged from Checkpoint 48 — the applicant's
own fields) and `application_custom_field_values` (new — the
application's own fields). Field keys are unique only per
`(tenant, entity_type)`, not per tenant overall, so a tenant can
validly define a `notes` field on both entities — a single shared
payload object would have no way to say which entity a given key
belongs to. Read side mirrors this: `JobApplicationResource` exposes
`applicant.custom_field_values` (applicant-level) and a top-level
`custom_field_values` (application-level) as two distinct maps.

**No stage/status gate on this endpoint — confirmed by reading the
code, not assumed — and this checkpoint does not add one.**
`JobApplicationController::update()` has never restricted editing by
`stage`/`status`; it allows changing `first_name`/`cover_letter`/etc.
on a `hired`/`rejected`/`withdrawn`/`archived` application today.
Custom field values inherit whatever the parent endpoint already
does, since they're written through the exact same `update()` action
— if a future checkpoint adds a stage/status gate there, custom
fields inherit it automatically, with no separate bypass path to
maintain.

**Custom field values are never copied elsewhere.** Application
custom fields stay on the `RecruitmentApplication` row.
`convertToEmployee()` and `startOnboarding()` were not modified —
neither reads nor writes `custom_field_values` today, so conversion
never copies application fields onto the new `Employee`, and
onboarding never copies them onto the new `LifecycleProcess`. A
future mapping from application fields to employee fields (once
Employee custom fields exist) needs its own separate, approved
design.

**Settings UI: simple tabs, not a dropdown.** `Settings/CustomFields.tsx`
gained an `entityType` state and two tabs ("Recruitment Applicants" /
"Job Applications") — a dropdown would be over-engineering for
exactly two supported entities. Switching tabs discards any
in-progress create/edit form state, since it belonged to the
previous tab's entity.

**`ApplicationShow.tsx`'s duplicated custom-field-editing logic was
extracted into one shared `CustomFieldsCard` component**, parameterized
by entity URL segment, payload key, and the values to render — used
twice on the same page (once per entity) rather than duplicating the
~80 lines of state/fetch/submit logic a second time.

### Future

Per `docs/platform-vision.md`: `lifecycle_processes`/`leave_requests`
next, `employees` last (once field-level visibility and sensitive
access design are stronger — unaffected by this checkpoint); a real
field-level read permission; a form/page designer; workflow
conditions/reports/dashboards/AI filters querying `custom_field_values`
generically across all entity types at once; a controlled, audited
application-field-to-employee-field mapping once Employee custom
fields exist; configuration export/import; and package/entitlement
limits.

## Field-Level Visibility and Sensitive Field Access (Checkpoint 50)

Before this checkpoint, `CustomFieldSensitivity` only affected audit
masking (Checkpoint 48) — any user who could view/edit the parent
entity could see and change every field's value regardless of its
tier. This checkpoint adds a real access-control layer on top of the
existing one, ahead of adding custom fields to more sensitive
entities (`employees`, `leave_requests`, `lifecycle_processes`, and
eventually compensation/disciplinary/health-and-safety data).

**Layering principle**: a field is visible/editable only when *all*
of the following hold — the tenant owns the record (`BelongsToTenant`,
unchanged), the module is enabled (`EnsureModuleEnabled`, unchanged),
the user holds the parent entity's own permission (`job_applications.view`/
`.update`, unchanged), **and** the user holds the field's sensitivity-tier
permission (new). Each layer is independent; none can be skipped by
satisfying another.

**Fixed permissions, not a configurable rules table — a researched
decision, not a guess.** Before designing this, the two existing
sensitive-field mechanisms in the app were read directly:
`employees.view_sensitive` (gates `personal_email`/`phone` by nulling
them inside `EmployeeResource` — no controller-level check) and
`documents.view_sensitive` (gates whole sensitive documents via
controller-level exclusion — no resource-level check). Both are
simple, fixed, hand-rolled boolean permissions; neither has any
configurable-rules precedent. This checkpoint follows that same
pattern rather than introducing the first per-tenant configurable
access-rules engine in the app — a configurable model is a documented
future direction (see `docs/platform-vision.md`), gated on real usage
evidence across more entities first.

**Three new permissions, no implied hierarchy**:

| Permission | Tier gated |
|---|---|
| `custom_fields.access_sensitive` | `sensitivity: sensitive` |
| `custom_fields.access_confidential` | `sensitivity: confidential` |
| `custom_fields.access_restricted` | `sensitivity: restricted` |

`normal` fields require none of these — only the existing parent-entity
permission, unchanged from Checkpoint 48/49. Holding a higher tier's
permission does not imply a lower tier's (and vice versa) — each is
checked independently via `CustomFieldSensitivity::requiredAccessPermission()`,
the single source of truth for the tier-to-permission mapping, shared
by both the enforcement service and the definitions resource.

**Default grants are deliberately conservative.** Tenant Admin holds
all three automatically via its existing blanket non-platform-permission
grant. HR Manager receives `custom_fields.access_sensitive` only,
mirroring the one existing precedent (`employees.view_sensitive`) —
not `access_confidential`/`access_restricted`. **HR Director receives
none of the three by default**, even though HR Director otherwise
mirrors many of HR Manager's grants — this is an intentional MVP
scope decision, not an omission: granting a new access tier by default
to a role that holds neither existing sensitive-access precedent today
would be a silent access expansion, not a neutral default. If a future
checkpoint decides HR Director should hold one or more tiers, that
must be an explicit, separately-approved grant.

**View vs. edit are genuinely separate, closing a gap the Employee
mechanism has today.** `employees.view_sensitive` only ever gated
*reading* `personal_email`/`phone` — there has never been a separate
write-side check, so a user who could edit an employee could always
write those fields even without view access. Checkpoint 50 does not
repeat that gap: `CustomFieldValueService::setValuesFor()` checks tier
access on write independently of whatever the read path allows,
returning `403` before any value validation runs, so a field
completely invisible to a user is also completely unwritable to them.

**Enforcement lives in the service layer, not the Resource or the
frontend** (decision 8 — explicit requirement, not just convenience):
- `CustomFieldValueService::getActiveValuesFor(..., User $viewer)` —
  signature gained a required `$viewer` param; a field whose tier
  permission the viewer lacks is `continue`'d past in the same loop
  that already skips disabled fields, so it is silently omitted from
  the result map. No error, no placeholder — indistinguishable from a
  disabled field from the read side.
- `CustomFieldValueService::setValuesFor()` — checks tier access
  immediately after the existing "is this `field_key` known" check
  and before `CustomFieldValueValidator::validate()` runs, calling
  `abort(403, ...)` if the actor lacks the required permission. There
  is no alternate payload shape that bypasses this: the check is keyed
  to the definition's own `sensitivity`, not to how the request names
  or nests the field.

**`CustomFieldDefinitionResource` gained computed `can_view`/`can_edit`
fields**, combining the entity's own view/update permission
(`CustomFieldEntity::valueViewPermission()`/`valueUpdatePermission()`)
with the field's tier permission — both must pass. These are computed
fresh per request against `$request->user()`, never stored or cached.
They exist purely so the frontend can decide what to render (hidden /
read-only / editable) — the backend enforcement above is what
actually matters; a client that ignored these flags and submitted a
disallowed field key would still be rejected by the service.

**`CustomFieldsCard` (in `ApplicationShow.tsx`) now gates per-field
on `can_view`/`can_edit`** instead of a single outer `canEdit` prop:
a field with `can_view: false` is not rendered at all; one with
`can_view: true, can_edit: false` renders read-only; both `true`
renders editable. The save button only appears if at least one
visible field is editable. The now-unused `canUpdateApplication`
variable and `useCan` import were removed rather than left dead.

**Audit masking (Checkpoint 48) is explicitly unchanged.** A
`sensitive`/`confidential`/`restricted` value is masked in the audit
log regardless of the *acting* user's own tier access — an audit log
may later be read by a different, less-privileged auditor, so masking
must not depend on who happened to write the value.

**No read-denial audit events were added.** Every existing
permission-denied path in this app (parent-entity `403`s included)
already goes unaudited; adding `custom_field.value_denied` or a
`field_visibility_rule.*` event now would be a noisy new pattern with
no existing precedent and no configurable-rules feature yet to attach
it to.

**`custom_fields.manage` and tier creation — deliberately not
over-built.** Today only Tenant Admin holds `custom_fields.manage`,
and Tenant Admin holds every tier permission automatically, so it can
already create/manage a field at any tier. No additional guard was
added for "a role with `.manage` but not every tier permission" — that
scenario does not exist yet. Documented here as a future rule, should
it ever arise: a non-Tenant-Admin role granted `custom_fields.manage`
should not be able to create or manage a field at a sensitivity tier
it cannot itself access, unless explicitly approved when that grant is
introduced.

### Future

A configurable, tenant-defined visibility-rules layer (role/permission-based,
`can_view`/`can_edit`/`can_export`/`can_report`/`can_use_in_workflow`/
`can_use_in_ai`, audited rule changes, safe defaults, always falling
back to these platform sensitivity permissions) is intentionally
deferred — see `docs/platform-vision.md` for the conditions that
should be met before building it, and why it must be an *override*
layer on top of this fixed model, never a replacement for it or a way
to bypass parent-entity access. A future system-field (not just
custom-field) visibility feature could reuse the same
`hasTierAccess()` primitive this checkpoint introduced, since it was
deliberately written generically rather than custom-field-specific.

## Employee Custom Fields (Checkpoint 51)

`Employee` (`App\Models\Employee`) is entity #3 for the custom-fields
engine — the reusability proof from Checkpoint 49 repeated a second
time, now against the platform's most sensitive core table, with the
Checkpoint 50 field-level visibility model already in place from the
very first line of code (unlike Checkpoint 48/49's own field-level
visibility gap, which existed until Checkpoint 50 closed it
retroactively — Employee custom fields never had that gap at all).

**Zero schema/service change, confirmed the same way as Checkpoint
49.** `CustomFieldEntity` gained one new case; `Employee` gained one
relation method (`customFieldValues()`, mirroring
`RecruitmentApplicant`'s); `UpdateEmployeeRequest` gained one new
validation rule; `EmployeeController::update()` gained one new
`CustomFieldValueService::setValuesFor()` call; `EmployeeResource`
gained one new `getActiveValuesFor()` call. No migration, no change to
`CustomFieldDefinitionService`, `CustomFieldValueService`,
`CustomFieldValueValidator`, or `CustomFieldAuditEvents`.

**Parent permission mapping**: `employees.view` gates reading Employee
custom field values (via `CustomFieldEntity::valueViewPermission()`),
`employees.update` gates writing them
(`valueUpdatePermission()`) — confirmed by directly reading
`routes/api.php` before assuming, the same posture as Checkpoint 49's
`job_applications.view`/`.update` mapping. Sensitivity tiers layer on
top exactly as Checkpoint 50 defined: `normal` needs only the parent
permission; `sensitive`/`confidential`/`restricted` additionally need
`custom_fields.access_sensitive`/`access_confidential`/`access_restricted`,
with no implied hierarchy between them. No new permission concept was
introduced for Employee — it reuses the identical tier model
unchanged.

### A real gap found and fixed: the `custom-fields/*` routes' module gate wasn't entity-aware

`routes/api.php`'s three `custom-fields/*` routes, and
`routes/web.php`'s `settings/custom-fields` page route, were hardcoded
to `module:recruitment` — correct only because every entity that
existed until now (`recruitment_applicant`, `job_application`)
belonged to Recruitment. `Employee` belongs to no toggleable module at
all (`TenantModule::Employees` is core, and
`TenantModuleService::isEnabled()` returns `true` unconditionally for
any non-toggleable module) — left unfixed, a tenant that disabled
Recruitment would have been unable to create, view, or manage Employee
custom field definitions at all, a real functional bug this
checkpoint's own scope would otherwise have shipped into, not a
hypothetical.

**Fix**: `CustomFieldEntity::requiredModule(): ?TenantModule` is now
the single source of truth for which module (if any) an entity
requires — `TenantModule::Recruitment` for `RecruitmentApplicant`/
`JobApplication`, `null` for `Employee`. The static `module:recruitment`
middleware was removed from all four routes (three API, one web page);
`CustomFieldDefinitionController` now checks the requirement at
runtime (`index()`/`store()` resolve it from the `$entityType` route
parameter, `update()` resolves it from the definition's own
`entity_type`), producing the identical 403 response shape
`EnsureModuleEnabled` already used, so no frontend error-handling
change was needed. `TenantModule::routeGroupPrefixes()`'s `Recruitment`
case no longer lists `custom-fields`/`settings/custom-fields` — those
routes are correctly module-agnostic at the route level now, and
`route:audit-module-gates` (which only asserts routes that *do*
belong to one specific module carry that gate) has no opinion on them,
by design. A direct regression test
(`test_employee_custom_fields_work_when_recruitment_module_disabled`/
`test_job_application_custom_fields_blocked_when_recruitment_module_disabled`)
proves both halves of the fix, not just the Employee side. Every
future entity belonging to a different toggleable module
(`lifecycle_processes` → Lifecycle, `leave_requests` → Leave) declares
its own requirement through this same method — no further route-layer
redesign needed.

### `employees.view_sensitive` vs. `custom_fields.access_*` — deliberately separate, never merged

These gate two structurally different things and must not be
confused: `employees.view_sensitive` is a fixed, hand-rolled gate over
exactly two **system columns** (`personal_email`, `phone`), enforced
entirely inside `EmployeeResource` — read-only, with no independent
write-side check (`UpdateEmployeeRequest`/`EmployeeController::update()`
confirmed to have none). `custom_fields.access_sensitive/confidential/
restricted` gate **tenant-defined custom fields only**, enforced
identically for both read and write in `CustomFieldValueService`. A
tenant could define an Employee custom field named
`emergency_contact_note` with `sensitivity: sensitive` — its
visibility is governed solely by `custom_fields.access_sensitive`,
with zero interaction with `employees.view_sensitive`. This checkpoint
does not change `employees.view_sensitive`'s behavior at all.

**Future security-hardening candidate, explicitly not fixed here**:
`employees.view_sensitive`'s write-side gap (any holder of
`employees.update` can write `personal_email`/`phone` blind, without
also needing `employees.view_sensitive`) is real, but redesigning
existing Employee system-field permissions is out of this checkpoint's
scope — logged for a future checkpoint that might introduce
`employees.update_sensitive` or equivalent backend enforcement,
deliberately not solved as a side effect of adding custom fields.

### Reserved keys — derived from the real schema, not assumed

`CustomFieldEntity::Employee`'s `reservedFieldKeys()` lists every real
`employees` column (`id`, `tenant_id`, `employee_number`, `first_name`,
`middle_name`, `last_name`, `preferred_name`, `work_email`,
`personal_email`, `phone`, `status`, `employment_type`,
`department_id`, `location_id`, `position_id`, `manager_employee_id`,
`start_date`, `probation_end_date`, `confirmation_date`, `user_id`,
`linked_at`, `linked_by`, `created_at`, `updated_at`, `created_by`,
`updated_by`, `deleted_at`), read directly from the migration rather
than assumed — several names in an earlier draft (`job_title`,
`department`, `location`, `hire_date`, `termination_date`, `manager_id`)
don't match the actual schema and were corrected. Relation names
(`department`, `location`, `position`, `manager`) are reserved
alongside their `_id` columns, defensively — a custom field literally
named `department` would otherwise read confusingly next to the real
nested `department` object `EmployeeResource` already returns.

### No recruitment-to-employee custom-field copying

Unchanged from Checkpoint 49's own non-copy guarantee:
`JobApplicationController::convertToEmployee()` still doesn't
reference `custom_field_values` anywhere, so recruitment custom fields
(applicant or application) are never copied onto the new `Employee`
row on conversion. Employee custom fields are entirely separate,
employee-scoped values from day one. A future application-to-employee
field mapping remains its own separate, approved design, unaffected by
this checkpoint.

### Frontend: `CustomFieldsCard` extracted to a shared component

`CustomFieldsCard` (previously defined inline inside
`ApplicationShow.tsx`, used twice for the two recruitment entities) is
now `resources/js/Components/CustomFieldsCard.tsx`, reused a third
time by `Employees/Show.tsx` rather than duplicated again. Generalized
via an `endpointUrl` prop (was hardcoded to
`` /job-applications/${applicationId} `` internally) and a widened
`payloadKey: string` (was a two-literal union type specific to
recruitment). Recruitment's own usage is behaviorally unchanged — both
`ApplicationShow.tsx` call sites now pass `endpointUrl` explicitly but
submit to the same endpoint and payload keys as before. Employee's
usage needs only one payload key (`custom_field_values`) since,
unlike `JobApplication`, `Employee` has no nested sibling entity by
which the same field key could collide.

### Future

Same as Checkpoint 48/49's Future list, now shared across all three
entities: a real field-level *system*-field visibility feature (reusing
`hasTierAccess()`), a form/page designer, workflow/report/dashboard/
AI-filter integration querying `custom_field_values` generically,
configuration export/import, and package/entitlement limits.
Additionally: the `employees.view_sensitive` write-side hardening
candidate noted above; `lifecycle_processes`/`leave_requests` remain
next per the roadmap, each declaring its own `requiredModule()`.

## Custom Forms Foundation (Checkpoint 52)

The first controlled custom-forms layer, built on top of the engine
proven across Checkpoints 48–51. **Employee** is the first supported
surface — chosen over Recruitment (whose Application Show page already
juggles two custom-field entities via two separate payload keys,
specifically to avoid a collision problem a forms layer would only
reintroduce) and over inventing a new "HR request" domain object
(which doesn't exist anywhere in the codebase and would be scope creep
disguised as a forms foundation).

### The load-bearing design decision: a form is metadata, never a second value pipeline

A `CustomForm`/`CustomFormSection`/`CustomFormField` row describes
**which existing custom fields appear, in which sections, in what
order, with which display overrides** — nothing else. Concretely:

- **No new write endpoint.** Submitting a form still means
  `PATCH /employees/{employee}` with `custom_field_values` — the exact
  call `CustomFieldsCard` already makes — scoped by the frontend to
  the field keys belonging to that form's sections.
  `CustomFieldValueService::setValuesFor()` is called with zero
  changes; every existing guarantee (tier check, disabled-field
  rejection, audit event) applies automatically because it's the same
  code path, not a parallel one.
- **No new read endpoint for values.** `EmployeeResource`'s existing
  `custom_field_values` remains the only source of values. The one
  genuinely new endpoint, `GET /custom-forms/{entityType}`, returns
  **structure** only (sections/fields/labels/help-text/order) — never
  values.
- **No new permission axis for submission.** Writing through a form is
  gated by exactly the same parent-entity permission
  (`employees.update`) and tier permission
  (`custom_fields.access_*`) as writing directly. There is no
  `custom_forms.submit`.
- **`CustomFieldValueValidator`/`CustomFieldDefinitionService`/
  `CustomFieldAuditEvents` are untouched** — zero changes to any of
  them. `CustomFieldEntity` itself is also untouched; forms reuse its
  existing `requiredModule()`/`valueViewPermission()`/
  `valueUpdatePermission()` as-is.

### Schema — no `tenant_id` on child rows, matching `custom_field_options`' own convention

```
custom_forms          (tenant_id, entity_type, form_key, name, description, status, sort_order, created_by, updated_by, timestamps)
custom_form_sections  (custom_form_id, section_key, title, description, sort_order, status, created_by, updated_by, timestamps)
custom_form_fields    (custom_form_section_id, custom_field_definition_id, label_override, help_text, placeholder, is_required_override, sort_order, status, created_by, updated_by, timestamps)
```

An earlier draft of this schema put `tenant_id` on both child tables.
Reading `custom_field_options`' actual migration first showed it has
**no `tenant_id` column at all** — tenant isolation is inherited
transitively through the parent FK, never denormalized onto the child
row, with defense-in-depth tenant checks living in the controller
instead. The corrected schema mirrors this exactly:
`CustomFormSection`/`CustomFormField` have no `BelongsToTenant`, no
`tenant_id`; every controller still independently re-verifies tenant
ownership by walking up to the owning `CustomForm` (`ensureFormBelongsToCurrentTenant()`),
the same "never trust that whatever gated the caller in already proved
this" posture used throughout this app. `custom_form_fields.custom_field_definition_id`
uses `restrictOnDelete()` — consistent with custom field definitions
never being hard-deleted in the first place.

### `entity_type` reuses `CustomFieldEntity` — no parallel `CustomFormEntity`

A separate `CustomFormEntity` enum would need to be kept in permanent
lockstep with `CustomFieldEntity` (both listing the same cases, both
needing their own `requiredModule()`), a real ongoing footgun — a
future entity added to one and forgotten in the other silently breaks
something. Since a form's fields must always belong to the entity the
form itself targets, and that entity must already be a supported
`CustomFieldEntity` case, `custom_forms.entity_type` is simply a
`CustomFieldEntity` value, validated with the identical
`tryFrom()`/422 pattern `CustomFieldDefinitionController` already
established.

### Module gating — the Checkpoint 51 pattern reused, not reinvented

`CustomFieldEntity::requiredModule()` is checked at runtime inside
`CustomFormController`/`CustomFormSectionController`/`CustomFormFieldController`
— no static `module:{key}` route middleware anywhere on the six new
routes. To avoid a second copy of the module-check logic (the whole
reason Checkpoint 51's bug happened — a hardcoded gate nobody updated
when a new entity arrived), the check itself now lives in a shared
trait, `EnsuresCustomFieldEntityModuleEnabled`, used by both
`CustomFieldDefinitionController` (refactored to use it, same
behavior, zero logic change) and the three new form controllers. A
paired regression test (`test_employee_form_works_when_recruitment_module_disabled`
/ `test_recruitment_form_blocked_when_recruitment_module_disabled`)
proves both directions in the same test run, the same "prove the fix
discriminates per entity, not just that it stopped blocking Employee"
reasoning Checkpoint 51's own test used.

### `can_view`/`can_edit` computed once, shared between two Resources

The can_view/can_edit computation (entity's own parent permission
combined with the field's sensitivity-tier permission) was extracted
from `CustomFieldDefinitionResource` into `CustomFieldAccessResolver::resolve()`,
a stateless static helper. `CustomFormFieldResource` embeds the field's
full `CustomFieldDefinitionResource` (never a second, divergent
definition shape), so both resources compute `can_view`/`can_edit`
through the exact same code path — no risk of the two silently
drifting apart over time.

### Field-level omission is server-enforced; form/section-status filtering is a legitimate frontend concern

`CustomFormSectionResource` omits a field from its `fields` array
entirely when the underlying `CustomFieldDefinition` is disabled, or
the requester's `can_view` is false — the same "omit means omit, never
a null-but-present entry, never the raw value" rule
`CustomFieldValueService::getActiveValuesFor()` already enforces for
values. This is unconditional — even for Settings > Custom Forms,
management of a disabled *custom field* happens through the existing
`Settings > Custom Fields` page, not through the forms UI, so nothing
is lost by omitting it here.

By contrast, `GET /custom-forms/{entityType}` returns **both active
and inactive forms/sections** — Settings needs to see and manage
disabled ones. The **entity-page renderer filters to `status === 'active'`
client-side** before rendering, the exact same split responsibility
`CustomFieldsCard`'s frontend already has for custom field definitions
(the backend returns everything a management UI needs; a live-render
consumer filters what it actually shows). This is not a security gap:
an inactive form/section carries no confidentiality implication to
someone who already holds `custom_forms.view` — the same reasoning
`CustomFieldDefinitionResource` already applies by always returning
inactive field definitions too.

### One real routing collision found and resolved before it ever shipped

The original route sketch included both
`GET /custom-forms/{entityType}` (list, for an entity) and
`GET /custom-forms/{customForm}` (show, for one form) — two GET routes
sharing an identical single-segment URI shape. Laravel resolves this
by always matching whichever route registered first, so the second
would never have been reachable. Since `index()` already eager-loads
every form's full `sections.fields.customFieldDefinition` tree, a
separate per-form fetch would only return already-available data — the
`show()` endpoint was dropped rather than solved with a fragile regex
route constraint.

### `is_required_override` is UI-only in this checkpoint — deliberately, not by oversight

`CustomFormField.is_required_override` can mark a field more strongly
required within a specific form's context (an asterisk, client-side
nudge) without mutating the underlying custom field's own global
`is_required`. It is **never consulted by `CustomFieldValueValidator`**
— teaching the validator about form-level overrides would mean giving
it forms-awareness, breaking the "no parallel system" principle this
checkpoint is built around. A form can visually suggest a field is
required more strongly than the underlying field enforces, but cannot
force it beyond what the field itself already requires. A future,
separately-designed checkpoint could add real form-level required
enforcement without needing to touch this schema.

### Frontend: one shared `CustomFieldInput`, `CustomFieldsCard` kept unconditionally

The per-field-type input switch (text/textarea/boolean/select/
multi-select) was extracted from `CustomFieldsCard` into
`resources/js/Components/CustomFieldInput.tsx`, reused by the new
`CustomFormRenderer` — the same "extract once genuinely needed twice"
reasoning behind Checkpoint 51's own `CustomFieldsCard` extraction, so
neither component carries a diverging copy of that switch statement.

`CustomFormRenderer` fetches `GET /custom-forms/employee`, filters to
active forms/sections client-side, and renders each active form as its
own independently-submittable card (mirroring `CustomFieldsCard`'s own
"one card per unit of data" shape) — submission goes through the exact
same `PATCH /employees/{employee}` call, scoped to that form's own
field keys. **`CustomFieldsCard` is kept, unconditionally, rendered
alongside `CustomFormRenderer`** on `Employees/Show.tsx` — a field
assigned to a form can appear in both places on the same page. This is
a deliberate MVP overlap, not an oversight: retiring or filtering
`CustomFieldsCard` now would add coupling this checkpoint doesn't need
before the forms approach is proven in practice.

### Audit behaviour — config events are new, value events are untouched

`CustomFormAuditEvents` (mirroring `CustomFieldAuditEvents`'s own
shape) emits `custom_form.created`/`updated`/`enabled`/`disabled`/
`section_added`/`section_updated`/`section_removed`/`field_added`/
`field_updated`/`field_removed` — configuration changes only. Value
changes continue firing `custom_field.value_updated` exclusively,
through the unmodified `CustomFieldAuditEvents::valueUpdated()` — no
form-submission value event was created; a test explicitly proves
`custom_form.value_updated` never exists in the audit log.

### Future

A real field-level *system*-field visibility feature; a form/page
designer (this checkpoint's simple up/down reorder is deliberately not
that); workflow/approval/report/dashboard/AI-filter integration
querying form structure generically; `is_required_override` becoming
real backend-enforced validation, once designed separately; retiring
or filtering `CustomFieldsCard` once the forms approach is proven;
`lifecycle_processes`/`leave_requests` as both custom-field *and*
custom-form entities, each declaring its own `requiredModule()`;
configurable field-visibility rules remain the Checkpoint 53 candidate
per the roadmap in `docs/platform-vision.md`, unaffected by this
checkpoint.

## Configurable Field Visibility Rules (Checkpoint 53)

An **override layer** on top of the fixed sensitivity-tier model from
Checkpoint 50 — `custom_field_visibility_rules` lets a Tenant Admin
grant a specific *role* view/edit access to a custom field beyond its
default tier, make a field read-only, or fully hide it, on a
per-field-per-role basis. This is deliberately the smallest version of
the "future" configurable-rules layer flagged in Checkpoint 50 and
Checkpoint 52's own `Future` sections — role-based only, no conditions,
no per-user rules, no export/report/AI enforcement yet.

### The central decision: unifying enforcement, not just adding a rule table

Before writing any rule logic, the existing code was read directly and
a real gap was found: `CustomFieldAccessResolver::resolve()` (built in
Checkpoint 52) and `CustomFieldValueService`'s own private
`hasTierAccess()` (from Checkpoint 50) were **two separate
implementations of the same tier check** — the resolver only ever fed
`CustomFieldDefinitionResource`/`CustomFormFieldResource`'s `can_view`/
`can_edit` UX metadata, while the service's own private method
independently gated real reads/writes. Adding rule-awareness to only
the resolver would have made the API *say* a rule granted access while
the service still silently enforced the old tier-only check underneath
— a UI/enforcement split that would have been a real security bug, not
a cosmetic one.

**Fix**: `CustomFieldValueService::getActiveValuesFor()`/`setValuesFor()`
now call `CustomFieldAccessResolver::resolve($definition, $viewer)['can_view'/'can_edit']`
directly; the private `hasTierAccess()` method was deleted entirely.
`CustomFieldAccessResolver` is now the **single source of truth** for
custom-field visibility/editability, consulted by every caller —
`CustomFieldDefinitionResource`, `CustomFormFieldResource` (via the
embedded definition resource), and `CustomFieldValueService`. A
regression test (`test_default_sensitivity_behaviour_unchanged_when_no_visibility_rule_exists`)
proves this refactor alone changes nothing when no rule exists — the
full existing Checkpoint 48–52 test suite (266 tests) passing unchanged
is the broader proof.

### Rule model — override-only, no effect/mode column

```
custom_field_visibility_rules (
    id, custom_field_definition_id, role_id,
    can_view, can_edit, status, created_by, updated_by, timestamps
)
```

No separate `effect`/`mode`/`allow`/`deny` column — `can_view`/
`can_edit` alone express the full range needed:

| `can_view` | `can_edit` | Meaning |
|---|---|---|
| `true` | `true` | Grant (beyond default tier, if it wouldn't otherwise pass) |
| `true` | `false` | Read-only for this role |
| `false` | `false` | Full deny for this role |
| `false` | `true` | **Rejected at write time** — editing without viewing makes no sense |

**Resolution order** (`CustomFieldAccessResolver::resolveTierOrRuleAccess()`):
if one or more *active* rules match any role the caller holds, those
rules **fully replace** (never merge with) the default tier
computation for that caller — **most-permissive-wins** aggregated
across matching roles (`Collection::contains()` OR across `can_view`,
same for `can_edit`). If no rule matches any held role, behavior falls
back to the unchanged Checkpoint 50 tier computation. Either path, the
entity's own parent permission (`valueViewPermission()`/
`valueUpdatePermission()`) is always AND'd in afterward, never
replaceable by a rule — a rule can widen or narrow *tier* access, never
grant access to an entity the caller couldn't otherwise view/edit at
all.

`custom_field_definition_id` is a `foreignUlid` (matching every other
custom-field table); `role_id` is a plain `foreignId` — `roles.id` is
`bigint`, unlike every tenant-owned table since Checkpoint 48, verified
by reading the actual `roles` migration before writing this one rather
than assuming ULID consistency. No `tenant_id` column — same
transitively-inherited-through-the-parent-FK pattern as
`custom_field_options`/`custom_form_sections`/`custom_form_fields`; a
`unique(custom_field_definition_id, role_id)` constraint means at most
one rule per field-role pair (edit the existing rule instead of
creating a second one).

### Role-based only — the direct-permission-grant asymmetry, stated plainly

`User::hasPermission()` checks **role-held permissions OR a direct
per-user grant** (`user_permissions`, from the original RBAC design) —
whichever is true. A visibility rule only ever matches a role the
caller **actually holds** (`$user->roles()->pluck('roles.id')`); it has
no way to see or react to a direct permission grant. Concretely: if a
user has no role granting `custom_fields.access_sensitive` but was
individually granted `employees.access_sensitive`-equivalent access via
`grantPermission()`, a visibility rule targeting "the role that would
normally hold that tier" does not affect them at all — they still fall
through to (and pass) the ordinary tier check on their own direct
grant, unaffected by any rule's existence. This is by design (test:
`test_direct_permission_grant_is_unaffected_by_role_based_rules`), not
a gap — extending rules to also match direct grants is future,
explicitly out of scope for this MVP.

### Tenant Admin role cannot be targeted — checked by slug, not `is_system_role`

Every one of the 20 seeded tenant roles (Tenant Admin, HR Director, HR
Manager, ..., Implementation Engineer) is `is_system_role: true` —
confirmed by reading `RoleSeeder` directly before designing this
safeguard. Rejecting rules against *any* system role (the guard
`RolePermissionController::update()` already uses for permission-set
edits) would make this feature unusable, since every realistic
rule-target role is a system role. Instead,
`CustomFieldVisibilityRuleService::resolveRoleForDefinition()` rejects
specifically `$role->slug === 'tenant-admin'` — Tenant Admin already
holds every non-platform permission and every sensitivity tier
automatically (`RoleSeeder` line 76), so a rule against it could never
do anything except accidentally *lock out* the one role that must
never lose configuration access to this feature (or any other Settings
page) in a tenant.

### Tenant safety — the same re-verification pattern, applied a fourth time

`CustomFieldVisibilityRule` has no `BelongsToTenant`/`tenant_id` of its
own — `CustomFieldVisibilityRuleController` walks up to the owning
`CustomFieldDefinition` (which *is* `BelongsToTenant`-scoped) and
compares `tenant_id` explicitly, the same posture used three times
before for `custom_field_options`/`custom_form_sections`/
`custom_form_fields`. The role itself is independently re-verified too:
`resolveRoleForDefinition()` rejects a `role_id` belonging to a
different tenant, a platform role, or the Tenant Admin role, before
ever constructing a rule — the frontend-supplied `role_id`/
`custom_field_definition_id` are never trusted.

### A second bug found while testing tenant isolation — not new to this checkpoint

Writing the tenant-isolation test for
`CustomFieldVisibilityRuleController::update()` surfaced an uncaught
500 (`TypeError`) instead of the expected 404. Root cause:
`$customFieldVisibilityRule->customFieldDefinition` walks a `belongsTo`
relation to `CustomFieldDefinition`, which **is** `BelongsToTenant`-scoped
— but that global scope applies to the relation query too, filtered to
whichever tenant is *currently resolved* from the request's subdomain,
not the tenant the rule's field actually belongs to. A rule belonging
to a different tenant than the caller's therefore resolves
`->customFieldDefinition` to `null`, and that `null` was flowing into a
non-nullable type-hinted parameter.

Checking whether the identical shape existed elsewhere found it does:
`CustomFormSectionController`/`CustomFormFieldController::update()`
(shipped, accepted, in production since Checkpoint 52) have the exact
same defect — both walk `section->form` (or `field->section->form`) to
reach a `BelongsToTenant`-scoped ancestor, and a cross-tenant
section/field ID resolves that relation to `null` the same way.
Checkpoint 52's own test suite never exercised "PATCH a cross-tenant
section/field directly by its own ID" (only cross-tenant *form* access
via `{customForm}` — which is safe by construction, since Laravel's
route-model-binding on a directly `BelongsToTenant`-scoped model 404s
before the controller ever runs — and a cross-tenant field ID
*submitted as a payload value* were covered, a different scenario
caught by the service's own explicit check).

**Fixed in all three controllers** with an explicit null check before
the tenant-id comparison, returning the intended 404 instead of
crashing. Two regression tests
(`test_cross_tenant_section_access_by_id_blocked`/
`test_cross_tenant_field_access_by_id_blocked`) were added to the
existing Checkpoint 52 `CustomFormApiTest.php` to close the coverage
gap that let this ship unnoticed. This was never a data-exposure bug —
no cross-tenant data was ever returned and no cross-tenant write ever
succeeded — but an uncaught 500 is a worse failure mode than a clean
404 (stack traces, noisier logs, less predictable client handling), so
it was fixed as part of this checkpoint rather than filed separately.

### API/resource behaviour

`CustomFieldDefinitionResource.visibility_rules` lists every rule for
that field regardless of status, via `CustomFieldVisibilityRuleResource`
(deliberately narrow: rule id, `role: {id, name}` only — never the
role's own permission set, never `custom_field_definition_id`/
`created_by`/`updated_by`). Gated by the same `custom_fields.view` that
already gates reaching the definition response at all — there is no
separate "management-only" response shape anywhere in this subsystem
to hook a stricter gate into, matching the same reasoning
`CustomFormResource` already applies to its own nested sections/fields.
`can_view`/`can_edit` on the definition itself already reflect the
final computed value (defaults + rules + aggregation + disabled-field
exclusion) with no separate "raw" vs. "computed" distinction exposed.

Two routes, both requiring `custom_fields.manage` (no new permission):
`POST /custom-fields/{customFieldDefinition}/visibility-rules` and
`PATCH /custom-field-visibility-rules/{customFieldVisibilityRule}`. No
static `module:{key}` middleware — module enforcement is resolved at
runtime from the definition's own `entity_type`, via the same
`EnsuresCustomFieldEntityModuleEnabled` trait every other custom-field/
custom-form controller uses. Rules are never hard-deleted, only
enabled/disabled via `status`, matching every other row in this
subsystem.

### Audit behaviour

`CustomFieldVisibilityRuleAuditEvents` (mirroring `CustomFormAuditEvents`'s
shape) emits `custom_field_visibility_rule.created`/`updated`/`enabled`/
`disabled` — configuration changes only. Metadata always includes
`field_key`, `entity_type`, `role_id`, `role_name`, and the previous/new
`can_view`/`can_edit` pair — **never a field value**, since these rows
never hold one. Value audit is completely unaffected: writes through a
rule-visible field still emit exactly `custom_field.value_updated`,
through the unmodified `CustomFieldAuditEvents::valueUpdated()`.

### Frontend

A "Visibility rules" section inside each custom field's existing edit
panel (`Settings/CustomFields.tsx`) — no separate page, no matrix
editor. Lists existing rules (role name, status badge, `can_view`/
`can_edit` checkboxes editable inline, enable/disable button); an
"add rule" row below lets a Tenant Admin pick an un-ruled, non-Tenant-Admin
role (sourced from the existing `GET /roles` endpoint) and set initial
`can_view`/`can_edit`. The `can_edit` checkbox is disabled whenever
`can_view` is unchecked, both in the add-rule row and per existing
rule, mirroring the backend's own `can_edit` requires `can_view` rule
client-side to avoid a guaranteed 422 — the backend remains the actual
enforcement boundary regardless.

### Two standing security-engineering lessons — apply to every future checkpoint, not just this one

Both bugs this checkpoint found and fixed are instances of a general
class of mistake, not one-off quirks of custom fields. Stated here as
standing rules for every checkpoint that follows:

1. **Access logic must never be duplicated between metadata/resource
   computation and real service-layer enforcement.** Before this
   checkpoint, `CustomFieldAccessResolver` (feeding API `can_view`/
   `can_edit` metadata) and `CustomFieldValueService` (enforcing actual
   reads/writes) were two independent implementations of the same
   tier check — a design that made it possible to "fix" one without
   the other, exactly the shape of bug that lets a UI correctly show a
   restriction while the backend silently doesn't enforce it (or vice
   versa: enforce something the UI no longer reflects). **Whenever a
   future checkpoint adds a new axis of access control (report
   filters, export gating, AI-visibility, a system-field equivalent),
   it must extend the one existing resolver/service call path, never
   introduce a second computation of "can this user see/edit this."**
   If a second call site needs the same answer, it must call the same
   function — copying the logic, even "temporarily," is the mistake to
   avoid.
2. **Cross-tenant relation walking must never assume a scoped relation
   resolves — a null result must produce 404/403, never an uncaught
   500.** Any controller that re-verifies tenant ownership by walking
   a `belongsTo` chain to a `BelongsToTenant`-scoped ancestor (the
   pattern used by every child row with no `tenant_id` of its own:
   `custom_field_options`, `custom_form_sections`, `custom_form_fields`,
   `custom_field_visibility_rules`, and any future one) must treat that
   relation as **nullable**, because `BelongsToTenant`'s global scope
   applies to the relation query too — a row belonging to a *different*
   tenant than the one currently resolved will silently resolve the
   relation to `null` rather than returning the other tenant's real
   row. A non-nullable type hint on the receiving check method turns
   that into an uncaught `TypeError` (500) instead of the intended
   404/403. **Every such ownership-check method must accept a nullable
   parameter and `abort_if($related === null, 404)` before comparing
   `tenant_id`.** This was found twice in one checkpoint (the new
   `CustomFieldVisibilityRuleController` and the already-shipped
   Checkpoint 52 `CustomFormSectionController`/`CustomFormFieldController`)
   precisely because it's easy to miss without a direct
   "PATCH a cross-tenant child row by its own ID" test — write that
   test for every new controller of this shape, not only a
   cross-tenant test on the parent.

### Future

Extending rules to also match direct permission grants, not just
roles; permission-based (rather than role-based) targeting; row-level
conditions (department/location/manager-chain); report/export/AI-filter
integration reusing `CustomFieldAccessResolver` (the resolver was
deliberately kept generic enough for this); a real system-field (not
just custom-field) visibility layer, reusing the same resolver
primitive; per-user rule overrides layered on top of role-based ones.

## Form Assignment / Employee UI Cleanup (Checkpoint 54)

Checkpoint 52 deliberately rendered `CustomFormRenderer` and
`CustomFieldsCard` unconditionally side-by-side on the Employee Show
page — a field assigned to a form could appear twice. That overlap was
an accepted MVP tradeoff, explicitly scoped to be revisited once
Checkpoint 53 proved visibility rules worked identically through both
surfaces (which it did — see Checkpoint 53's live smoke test). This
checkpoint closes the overlap: `CustomFormRenderer` shows fields
assigned to an active form; `CustomFieldsCard` becomes a fallback for
active, viewable fields not assigned to any active form.

### The central decision: compute "assigned" client-side, from data already fetched

`GET /custom-forms/{entityType}` already nests, per field, the full
`CustomFieldDefinitionResource` (`sections[].fields[].custom_field_definition`),
including `field_key`, `can_view`, `can_edit`, `status`. The frontend
already fetched both `GET /custom-forms/employee` and
`GET /custom-fields/employee` before this checkpoint — so "which field
keys are assigned to an active form" is a set-difference computable
entirely client-side, with **no new backend endpoint, no new field on
any Resource, no new query parameter**.

This was evaluated as a genuine option (a precomputed
`is_assigned_to_active_form` flag on `CustomFieldDefinitionResource`)
and explicitly rejected: it would touch a Resource shared by three
entities (recruitment_applicant, job_application, employee) for an
Employee-only UI concern, and there is no new trust boundary being
crossed that would require server-side computation — both responses
are already scoped to the requesting viewer's own permissions/rules,
so each viewer correctly computes their own "assigned" set from their
own data. A field a viewer cannot see is simply absent from their own
`forms` response (`CustomFormSectionResource` already omits it) and
therefore never counted as "assigned" for them either — no
cross-viewer leakage is possible, because nothing here reads or infers
anyone else's view of the data.

### Data flow: lifted into `Employees/Show.tsx`, not owned by `CustomFormRenderer`

`CustomFormRenderer` no longer self-fetches `GET /custom-forms/{entityType}`.
`Employees/Show.tsx` fetches it once, computes `assignedFieldKeys` from
the result, and passes the raw forms array down to `CustomFormRenderer`
as a prop (now purely presentational) and the computed key set down to
`CustomFieldsCard` as a new optional `excludeFieldKeys` prop. This was
chosen over an alternative (keep `CustomFormRenderer` self-fetching,
have it report the resolved key set back up via a callback prop) for
two reasons: it avoids a "child reports state to parent" data flow that
reads awkwardly, and it avoids a flash-then-hide render order problem —
`CustomFieldsCard` never has a chance to render a field the moment
before `assignedFieldKeys` becomes known, since both come from state
`Employees/Show.tsx` already owns before either child renders.

`CustomFieldsCard.tsx` gained one new optional prop,
`excludeFieldKeys?: Set<string>`, applied as one more filter
(`visibleDefs`) alongside its existing, unchanged `status === 'active'
&& can_view` filter. `excludeFieldKeys` can only ever *narrow* what
this component would otherwise show — it has no way to widen access,
since it's applied strictly after the existing access filter, never
instead of it. The component's submit logic was updated to submit only
`visibleDefs` (fields it actually renders), not `defs` (every field it
fetched) — a field excluded as form-assigned was never being edited in
this card in the first place, so it must never be silently included in
this card's own submission payload either.

### A real gap found while confirming this checkpoint's own requirements

The required behavior list for this checkpoint states plainly:
disabling one form-field row must return that field to the fallback
card. Verifying this surfaced that **`CustomFormRenderer` never
actually hid a disabled form-field row** — it filtered forms and
sections by their own `status === 'active'`, but individual
`section.fields` entries were rendered unfiltered by their own status.
A disabled row (removed from a section without disabling the whole
section or form) kept rendering, silently, in Checkpoint 52's shipped
code — confirmed by the existing test suite only ever asserting the
*audit event* fired on disable, never that the field actually
disappeared from what the renderer would show.

**The fix does not live in `CustomFormSectionResource`.** An initial
plan to filter inactive `CustomFormField` rows out of that Resource
directly was reconsidered before implementation: `Settings > Custom
Forms` reads the exact same `GET /custom-forms/{entityType}` response
to show a disabled row's "Disabled" badge and its "Restore" button — a
resource-level filter would make a disabled row's data vanish from the
API entirely, permanently breaking that management UI (there is no
other endpoint or query parameter that returns it). This mirrors the
resource's own pre-existing, deliberate posture for forms and sections
— both are returned active-and-inactive by the Resource, precisely so
Settings can manage disabled ones, with the *entity-page renderer*
responsible for filtering to active-only client-side. The fix applies
the identical pattern to individual field rows: `CustomFormSectionResource`
is unchanged; `CustomFormRenderer.tsx` gained the missing
`status === 'active'` filter on `section.fields`, in the same places it
already filters `form.sections`.

Backend test coverage for this reflects that split precisely:
`test_disabled_form_field_row_keeps_reporting_its_status` proves the
row's data and status remain fully present and toggleable in the API
response (protecting Settings' Restore button from ever regressing),
rather than asserting the row disappears from the raw response — the
disappearance is proven at the renderer/assignment-computation level
instead, via the live smoke test and source-level review, since no
frontend test runner exists in this repo.

### Active/inactive cascading, and why it needed no new logic

`assignedFieldKeys` in `Employees/Show.tsx` requires an unbroken chain
of `form.status === 'active'` → `section.status === 'active'` →
`field.status === 'active'` before counting a field as assigned — the
exact same three-level chain `CustomFormRenderer` itself now requires
before rendering a field (post-fix). A field disqualified at any level
(disabled form, disabled section, or disabled form-field row)
correctly reappears in the fallback `CustomFieldsCard`, verified by a
dedicated live smoke test exercising all three levels independently. A
field whose *underlying custom field* is disabled, or that a
visibility rule denies, was never present in `forms` to begin with
(`CustomFormSectionResource` already omits both cases, unchanged since
Checkpoint 52/53) — it's simply absent from `assignedFieldKeys` and
also excluded from `CustomFieldsCard`'s own independent `can_view`/
`status` filter, so it appears in neither surface, with zero new code
needed to make that true.

### Duplicate cross-form assignment — explicitly allowed, not restricted

The only DB-level uniqueness constraint on `custom_form_fields` is
`unique(custom_form_section_id, custom_field_definition_id)` — scoped
to one section. Nothing has ever stopped the same custom field being
added to a second section or a second form entirely; `Settings > Custom
Forms`' own field-picker (`fieldsAlreadyInForm()`) only dedupes within
one form. This checkpoint makes no change to that: a field assigned to
two active forms renders in both, independently
(`test_field_assigned_to_two_active_forms_appears_in_both`), and is
excluded from the fallback card either way, since `assignedFieldKeys`
is a simple set (membership, not count).

### Settings > Custom Forms — a help-text line, not a warning dialog

A single non-blocking `<p>` was added next to the "add existing field"
picker: *"Fields added to an active form no longer appear in the
unassigned custom fields card on Employee profiles."* No modal, no
confirmation step — consistent with every other config action in this
subsystem (creating a field, disabling a form) never requiring
confirmation.

### Frontend security posture — unchanged, restated explicitly

This checkpoint is a rendering-location change only. Every one of the
following remains enforced exactly as before, entirely server-side,
unaware this checkpoint exists: tenant isolation, parent-entity
permissions, field-level visibility rules, `can_view`/`can_edit`,
disabled-field omission, `CustomFieldValueValidator`, `CustomFieldValueService`
writes, and audit masking. `excludeFieldKeys` and `assignedFieldKeys`
are pure UX — a client that ignored them entirely and submitted a
"form-assigned" field's value through `CustomFieldsCard` would still
succeed or fail by exactly the same rules it always has, since both
cards submit through the identical `PATCH /employees/{employee}` call
with `custom_field_values`.

### Standing product-engineering lesson

The `CustomFormSectionResource` conflict found while building this
checkpoint (see above) generalizes beyond this one fix — stated here as
a standing rule for every future checkpoint, not a one-off note:

**When one API response serves both a management surface and a
live-rendering surface, do not remove management data blindly to fix a
rendering problem.** Separate the two concerns explicitly: the
management surface (Settings) needs to see and act on every row
regardless of status; the rendering surface (an entity page) needs to
see only what should currently display. The fix belongs in whichever
layer owns the *rendering* decision — here, the client-side renderer —
never in the shared response both surfaces depend on, unless the
management surface's own needs are re-verified first. This same shape
already existed twice in this codebase before this checkpoint (forms'
and sections' own active/inactive status, both left unfiltered at the
resource level for exactly this reason) — the mistake this checkpoint
almost made was not recognizing the third instance of the same
pattern before writing code.

### Future

Recruitment (`ApplicationShow.tsx`) still renders its two
`CustomFieldsCard` instances unconditionally, since Recruitment has no
Custom Forms yet — the same assignment-computation pattern generalizes
directly whenever that changes. A form/page designer, a form-assignment
wizard, conditional sections, and form versioning remain explicitly out
of scope, as they were in Checkpoint 52.
