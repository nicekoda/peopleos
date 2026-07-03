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
