# PeopleOS

Enterprise Human Resource Intelligence Platform. Built with Laravel + PostgreSQL.

## Local Development Setup (Windows / Laragon)

**Requirements**

- PHP 8.3+
- PostgreSQL (this project connects to `peopleos_dev`)
- Composer

**PostgreSQL PHP extensions**

This project requires the `pdo_pgsql` and `pgsql` PHP extensions.

- **CLI** (`artisan`, `composer`): scoped to this project only, via a
  project-local `php.ini` at the repo root (git-ignored, machine-specific)
  with the extensions enabled. Use the wrapper scripts instead of calling
  `php`/`artisan`/`composer` directly, since Windows PHP CLI does not pick
  up a `php.ini` from the current working directory automatically:
  ```bash
  ./artisan.bat migrate
  ./artisan.bat test
  ./composer.bat install
  ```
  These wrappers set `PHPRC` to the project's `php.ini` before invoking the
  underlying command. If `php.ini` doesn't exist yet (fresh clone), copy it
  from your machine's base Laragon `php.ini` and uncomment the `pdo_pgsql`
  and `pgsql` extension lines.

- **Web server (Apache)**: Laragon's Apache uses `mod_php`, which loads a
  single `php.ini` for the entire Apache process — there is no native
  per-vhost override. True per-project isolation for browser-facing
  requests would require switching to FastCGI/PHP-FPM, which is out of
  scope for local development right now. Instead, `pdo_pgsql` and `pgsql`
  are enabled directly in Laragon's active Apache PHP `php.ini`
  (`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini` at time of
  writing — check `PHPIniDir` in `C:\laragon\etc\apache2\mod_php.conf` if
  the PHP version changes). This applies to every project served by this
  Apache instance, not just PeopleOS.
  - **Why this is acceptable:** loading a DB driver doesn't grant any
    project access to data — each project still needs its own valid
    credentials to connect to anything. This is local development only.
  - **Production** will use a controlled server/container image where
    required PHP extensions are explicitly installed and no unnecessary
    extensions are enabled — this global-`php.ini` approach is a local-dev
    convenience, not a pattern to carry into production.
  - After editing this file, restart Apache via Laragon (Menu → Apache →
    Restart, or Reload) to pick up the change.

**Database configuration**

Copy `.env.example` to `.env` and fill in your local PostgreSQL credentials
(`DB_CONNECTION=pgsql`). Then run:

```bash
./artisan.bat migrate
```

**Local HTTPS and client subdomains**

PeopleOS identifies tenants/clients by subdomain (e.g.
`client1.peopleos.test`, `client2.peopleos.test`). Locally this uses:

- A wildcard SSL certificate for `peopleos.test` and `*.peopleos.test`,
  generated with [mkcert](https://github.com/FiloSottile/mkcert) and stored
  at `C:\laragon\etc\ssl\peopleos.test\` (outside the repo — never commit
  certs or keys).
- Two Apache vhost files in `C:\laragon\etc\apache2\sites-enabled\`:
  - `auto.peopleos.test.conf` — the plain `:80` vhost, owned by Laragon.
    Laragon regenerates this whenever it rescans `www/`, so nothing custom
    lives here.
  - `ssl.peopleos.test.conf` — the `:443` vhost with `SSLEngine on` and
    `ServerAlias *.peopleos.test`. Deliberately **not** prefixed `auto.` so
    Laragon never touches or regenerates it — this is what makes the SSL
    config durable across Laragon reloads/rescans.
- Windows' hosts file cannot do wildcard entries, so **each client
  subdomain needs its own line** in
  `C:\Windows\System32\drivers\etc\hosts`, e.g.:
  ```
  127.0.0.1   peopleos.test
  127.0.0.1   client1.peopleos.test
  127.0.0.1   client2.peopleos.test
  ```
  Editing the hosts file requires an elevated (Administrator) terminal.

A helper for adding these entries when a new tenant is provisioned is
planned as part of the tenant foundation checkpoint.

## Frontend (Inertia + React + TypeScript + Tailwind)

Added in Checkpoint 16 — a secure, permission-aware UI shell over the
existing `/api/v1` backend. **The frontend is presentation only; it is
never the security boundary** — see [`docs/security.md`](docs/security.md#frontend-security-model)
for the full rule.

**Stack**: Inertia.js (server-side adapter: `inertiajs/inertia-laravel`),
React 19, TypeScript, Tailwind CSS 4, Vite.

**Dev server** (hot-reloading, run alongside `php artisan serve` or your
Apache/Laragon vhost):

```bash
npm run dev
```

**Production build** (required before deploying, or whenever you want
`php artisan serve`/Apache to serve the built assets instead of the dev
server):

```bash
npm run build
```

**Type-checking** (Vite's build does not fully type-check TypeScript —
run this separately):

```bash
npx tsc --noEmit
```

**Directory layout**:

```
resources/js/
  app.tsx              — Inertia entry point
  Pages/               — one component per Inertia::render(...) call
  Layouts/AppLayout.tsx — sidebar + topbar shell for authenticated pages
  Components/          — reusable UI primitives (Button, Card, PermissionGate, FormField, ...)
  hooks/useCan.ts       — permission-aware UI helper (UI-only, not security)
  lib/api.ts            — shared axios client + error normalizer for talking to /api/v1
  types/index.d.ts      — shared Inertia page props (mirrors HandleInertiaRequests::share())
  types/employee.ts      — Employee/EmployeeFormPayload types (mirrors EmployeeResource)
```

**Employee Records UI** (Checkpoint 17) is the first real module screen
— `/employees`, `/employees/create`, `/employees/{id}`,
`/employees/{id}/edit`. It fetches its data **client-side** from the
existing `/api/v1/employees` endpoints via `resources/js/lib/api.ts`,
not via server-rendered Inertia props — see `docs/architecture.md` for
why, and reuse this same pattern (`api.ts` + `toApiError()`) for any
future module UI rather than inventing a new one per module.

**Leave Management UI** (Checkpoint 18) — `/leave` (list + inline
balances), `/leave/create`, `/leave/{id}` (detail, with submit/cancel/
approve/reject actions). Same client-side-fetching pattern; reuses
`lib/api.ts` unchanged apart from a tightened `409` default message.
The frontend cannot know the full manager-hierarchy approval scope
(`ManagerHierarchyService::directlyManages()`) — Approve/Reject buttons
render based on permission and status alone, and a resulting `403` is
handled the same safe way as any other. See `docs/security.md`.

**Document Repository UI** (Checkpoint 19) — employee-scoped, not a
tenant-wide document centre yet: `/employees/{id}/documents` (list),
`/employees/{id}/documents/upload`, `/employees/{id}/documents/{id}`
(metadata only, no file preview). Reuses the existing
`/api/v1/employees/{employee}/documents` and `/api/v1/document-categories`
endpoints and the same `lib/api.ts` error contract, plus a new
`lib/download.ts` helper for safe authenticated blob downloads (never a
raw browser navigation to the API URL, which could otherwise offer a
403/404 JSON error body up as if it were the downloaded file). See
`docs/security.md`.

**Policy Management UI** (Checkpoint 20) — `/policies`, `/policies/create`,
`/policies/{id}`, `/policies/{id}/edit`, `/policies/{id}/versions/create`,
`/policies/{id}/assign`, `/policies/{id}/acknowledgements`. Same
client-side-fetching pattern, reusing the existing `/api/v1/policies`
endpoints plus one new small read-only addition this checkpoint,
`GET /api/v1/policies/{policy}/versions` (gated `policies.view`, scoped
through `$policy->versions()`) — without it, the UI had no way to show
current-version content or let HR pick which draft to publish. Policy
version content is always rendered as plain text, never
`dangerouslySetInnerHTML`. See `docs/security.md`.

**Dashboard Foundation** (Checkpoint 21) — `/dashboard` now shows real,
permission-aware summary cards fetched from a new `GET /api/v1/dashboard`
endpoint, replacing the Checkpoint 16 placeholder. A new `dashboard.view`
permission only grants reaching the endpoint at all — every card is
still independently gated by its own module permission (`employees.view`,
`leave.view`, `documents.view`, `policies.view`, etc.), so `dashboard.view`
alone can never surface any module's data. Document cards are
deliberately scoped to the viewer's own linked employee only (no
`documents.view_all`-equivalent permission exists yet to safely gate a
tenant-wide count). Platform Super Admins never call the dashboard API
at all — they see a plain, safe "platform dashboard not available yet"
message instead. See `docs/security.md`.

**Settings Foundation** (Checkpoint 22) — `/settings` now shows real,
permission-aware section cards, replacing the Checkpoint 16 placeholder.
A new `tenant.settings.view` permission only grants reaching the page —
each section card is independently gated by its own permission
(`tenant.view`, `users.view`, `document_categories.view`,
`leave_types.view`, `audit.view`), same "access, not data" two-layer
design as the Dashboard. `/settings/company` is the one fully real
section: view/edit backed by a new singleton `GET`/`PATCH /api/v1/tenant`
endpoint (no `{id}` — always the caller's own tenant), editing only
`name`; `subdomain`/`status`/`tenant_id` and any future billing/security
field can never be changed through it. At the time this checkpoint
shipped, every other section (Users & Access, Document Categories,
Leave Types, Security & Audit, Integrations) was a permission-gated
"coming later" placeholder with no data fetched — Users & Access,
Document Categories, Leave Types, and Security & Audit were built out
in Checkpoints 23–25 (only Integrations, and the static Billing &
Subscription card, remain placeholders — see Checkpoint 26 below).
Platform Super Admins get a safe static Settings page and are blocked
from `/api/v1/tenant` with a clean `403`. See `docs/security.md`.

**Users & Access Management UI** (Checkpoint 23) — `/settings/access`
is now a real hub linking to `/settings/access/users` (list),
`/settings/access/users/{id}` (status changes, role assignment,
employee linking), and `/settings/access/roles` (read-only). Backed by
new `User`/`Role`/`Permission` APIs — the first tenant-scoped models in
this app that don't use `BelongsToTenant` (login must work before a
tenant is known, and Platform Super Admins need cross-tenant
visibility), so every query in the new controllers manually filters by
tenant — the primary defense here, not a backstop on top of a global
scope. A tenant can never be left without an active Tenant Admin: any
status change or role removal that would do so is rejected with `409`,
regardless of who performs it. Role/status management stays
Tenant-Admin-only this checkpoint; HR Manager keeps its existing
read-only `users.view`. Employee linking reuses the existing
Checkpoint 11 endpoints unchanged. See `docs/security.md`.

**Audit Log Viewing UI** (Checkpoint 24) — `/settings/security` now
links to `/settings/security/audit-logs` (list, with `module`/`action`/
`severity`/date-range filters) and `/settings/security/audit-logs/{id}`
(detail), backed by new read-only `GET /api/v1/audit-logs` and
`GET /api/v1/audit-logs/{auditLog}` endpoints. `AuditLog`, like
`User`/`Role` (Checkpoint 23), doesn't use `BelongsToTenant` — every
query manually filters by tenant. A new `AuditValueSanitizer` masks
sensitive keys (passwords, tokens, secrets, bank/salary/medical values,
leave/rejection reasons, storage paths, and more) in `metadata`/
`old_values`/`new_values` before they ever leave the API — genuinely
new protection for `metadata`, which was never masked before this
checkpoint, and defense-in-depth for `old_values`/`new_values`, which
were already masked at write time. `ip_address`/`user_agent` are
omitted from the API response entirely. No create/update/delete audit
routes exist — audit logs remain append-only, enforced independently
at the model layer since Checkpoint 5. See `docs/security.md`.

**Document Categories & Leave Types Admin UI** (Checkpoint 25) —
`/settings/document-categories` and `/settings/leave-types` are now
real list/create/edit UIs (replacing the Checkpoint 22 placeholders),
built entirely on the existing, already-tested APIs from Checkpoints 9
and 12 — no new backend endpoints were needed. `DocumentCategoryResource`/
`LeaveTypeResource` were tightened to drop `created_by`/`updated_by`,
which had no use in an admin UI. Delete actions are labelled "Archive"
throughout, since both `destroy()` methods are soft-delete-only. Leave
Type editing has one deliberate exception to this app's usual "omit
blank fields" form convention: a blank `max_days_per_year` is sent as
an explicit `null`, not omitted — otherwise a capped leave type could
never be turned back into an unlimited one. See `docs/security.md`.

**Demo Readiness & UI Polish** (Checkpoint 26) — no new module; this
checkpoint made the ten existing modules demo-ready. Fixed two stale
rough edges found during review: the Settings nav link was still gated
on `employees.update` instead of `tenant.settings.view` (so HR Officer/
Auditor couldn't see the link despite already having page access), and
the Settings hub still showed "Coming later" on five sections
(Users & Access, Roles & Permissions, Document Categories, Leave Types,
Security & Audit) that were fully built in Checkpoints 23–25. Added a
new `DemoDataSeeder` (realistic UESL-tenant employees/leave/documents/
policies) and three new demo logins (`hr.officer@`/`line.manager@`/
`auditor@uesl.peopleos.test`) so every role in a live demo has a real,
permanent seeded account. Resolved the Checkpoint 25 bundle-size
advisory by switching `app.tsx`'s Inertia page resolver from an eager
to a lazy `import.meta.glob` (standard `laravel-vite-plugin/inertia-helpers`
pattern) — main chunk dropped from 500.41 kB to 321.57 kB gzip, no
custom code-splitting added. See `docs/demo-guide.md` for the full demo
walkthrough and `docs/security.md`/`docs/architecture.md` for the
technical detail.

See `docs/architecture.md`/`docs/security.md`/`docs/api.md` for the full
design, what's shared with the frontend (and what never is), and the
future module rollout plan.

## Deployment Readiness (Checkpoint 27)

No new product feature — this checkpoint reviewed the app against what
a real deployment requires and wrote it down: `.env.example` now
documents every production-relevant variable inline (`APP_DEBUG`,
`SESSION_SECURE_COOKIE`, etc.), two new docs cover the full setup-to-
production path (`docs/deployment.md`, `docs/production-readiness.md`),
and a real, tested Artisan command
(`php artisan route:audit-tenant-scoping`) replaces a scratch script
that used to be re-created by hand before every checkpoint. One real
gap was found and documented (not silently patched, since the actual
production hosting topology isn't known yet): no `TrustProxies`
configuration exists in `bootstrap/app.php`, which matters only if this
app is ever deployed behind a reverse proxy/load balancer that
terminates TLS — see `docs/security.md` and `docs/production-readiness.md`.

## RBAC Role & Permission Management UI (Checkpoint 28)

`/settings/access/roles` gains create/edit/permission-assignment on
top of Checkpoint 23's read-only list — but only ever for **custom**
(tenant-admin-created) roles. Built-in/seeded roles (Tenant Admin, HR
Manager, etc.) are permanently view-only in this checkpoint: no name/
description edit, no permission add/remove, no delete — the "safer
MVP" approach, chosen over building a runtime check for whether a
given permission change would leave the tenant without an effective
admin path. A new `is_system_role` column on `roles` (backfilled `true`
for every existing seeded role) is what makes this distinction
possible; any role created through the new API is always
`is_system_role: false`. Permission assignment is gated by
`permissions.assign` — an existing, previously-unused permission key,
not a newly-invented one. No role deletion exists yet, for any role.
See `docs/security.md` for the full security design and
`docs/architecture.md` for the schema/architecture decisions.

## CI & Automated Quality Gate (Checkpoint 29)

No new product feature — this checkpoint automates the five checks
every prior checkpoint has run manually. Locally:

```bash
composer run quality   # test -> pint --test -> route:audit-tenant-scoping
npm run quality          # typecheck (tsc --noEmit) -> build
```

`.github/workflows/ci.yml` runs the same five checks on every push/PR
to `main`/`master`: PHP + Node setup, `composer install`/`npm ci`, a
real PostgreSQL service container for migrations and the tenant-route
audit, then the backend test suite (which — deliberately unchanged —
still runs against `phpunit.xml`'s in-memory SQLite, not the
PostgreSQL service; see `docs/quality-gate.md` for why these coexist
in one workflow rather than contradicting each other), Pint, the
TypeScript check, and the frontend build. No secrets are committed —
`APP_KEY` is generated fresh every CI run, and the PostgreSQL service's
credentials are throwaway, local to that run only. The live HTTPS
smoke test this project has always run by hand is **not** automated
(it depends on local subdomains/certs/browser sessions CI doesn't
have) — it remains a documented required manual step after CI passes.
See `docs/quality-gate.md` for the full reference.

## GitHub Remote & CI Verification (Checkpoint 30)

This repository is now pushed to a **private** GitHub repository and
runs on **GitHub Free** — a confirmed business constraint: nothing in
this project's CI/deployment/operations design assumes a paid GitHub
plan. The first real GitHub Actions run surfaced one CI-only bug (no
product code affected): the workflow ran the backend test suite
*before* the frontend build, so every test rendering a real Inertia
page failed on a missing `public/build/manifest.json` — fixed by
reordering the workflow to build first. `.github/workflows/ci.yml` also
now cancels superseded runs on the same branch and caches Composer
dependencies, keeping it well within GitHub Free's 2,000 Actions
minutes/month for private repos. See `docs/quality-gate.md` §5 for the
full GitHub Free reasoning and what would trigger reconsidering a paid
plan later.

## Employee Lifecycle Foundation (Checkpoint 32)

Departments, Positions, and Locations — three lookup entities that
existed at the schema level since Checkpoint 6 (used only for employee
FK validation) — now have full admin CRUD:
`/settings/{departments,positions,locations}(/create)(/{id}/edit)`,
backed by `/api/v1/{departments,positions,locations}`. Same three-layer
tenant-isolation pattern as every other top-level admin resource
(`tenant.matches` → `BelongsToTenant` scope → explicit controller
check), same permission tiers as Document Categories (Tenant Admin
full; HR Manager full; HR Officer view/create/update, no delete; Line
Manager and Auditor view-only; Employee none). `slug` is always
server-generated from `name`, never accepted from the frontend.
Employment Type deliberately stays a fixed enum, not a fourth lookup
table — it's a stable, universal classification, unlike the
tenant-specific structures the other three represent.

A real, pre-existing validation gap was closed as part of this
checkpoint: `StoreEmployeeRequest`/`UpdateEmployeeRequest`'s
`department_id`/`location_id`/`position_id` checks (since Checkpoint 6)
never excluded archived or soft-deleted rows — the same class of bug
Checkpoint 9 found and fixed for `document_categories`. Fixed the same
way, and verified an employee's *existing* assignment survives an
unrelated field update even after that department is later archived
(the fields are `nullable` with no `sometimes`, so they're only
re-validated when actually supplied). `EmployeeResource` also gained
resolved `department`/`location`/`position` `{id, name}` objects,
alongside the existing raw IDs, so the Employee UI can finally show
real names instead of bare identifiers. See `docs/architecture.md` and
`docs/security.md` for the full design.

## Onboarding & Offboarding Foundation (Checkpoint 33)

A practical workflow foundation, not a workflow builder: two new
tables (`employee_lifecycle_processes`, `employee_lifecycle_tasks`),
reached at `/lifecycle(/create)(/{id})(/{id}/edit)(/{id}/tasks/create)`
and `/lifecycle-processes`/`/lifecycle-tasks` in the API. One generic
`lifecycle.*` permission set covers both onboarding and offboarding —
`type` is just a column, not two parallel modules. Status transitions
(`draft → in_progress → completed/cancelled` for processes, `pending →
in_progress → completed/skipped` for tasks) are centralized and
validated against the record's current state, the same pattern
`LeaveRequestStatus` established in Checkpoint 12; a completed/
cancelled process or a completed/skipped task rejects every further
mutation.

Line Manager and Employee hold the *identical* permission set
(`lifecycle.view` + `lifecycle.complete_task`) despite needing
different visibility — no permission key distinguishes "see my direct
reports' processes" from "see only my assigned tasks." A new
`LifecycleVisibilityService` resolves this from relationship data
instead of a permission key, documented as a deliberate design
decision, not an oversight. Two genuine, identically-shaped permission
gaps (HR Officer lacking `employees.view`/`users.view` needed to
populate the process/task pickers, despite holding the create/assign
actions) were found while building the frontend and fixed the same way
Checkpoint 19 fixed an analogous gap — both confirmed with the project
owner individually before granting, since `users.view` is a broader,
more sensitive resource than `employees.view`. See `docs/architecture.md`
and `docs/security.md` for the full design.

## HR Documents & Letter Generation Foundation (Checkpoint 34)

A templates-and-records foundation, not document automation: two new
tables (`hr_document_templates`, `hr_generated_documents`), reached at
`/settings/hr-document-templates(/create)(/{id}/edit)` and
`/hr-documents(/create)(/{id})` in the UI, `/hr-document-templates` and
`/hr-generated-documents` in the API. Content-only (Option A, approved)
— no PDF/DOCX file is generated this checkpoint; `rendered_content` is
stored as plain text and `employee_document_id` stays `null`, the same
forward-compatible-placeholder shape `policy_versions.employee_document_id`
already established. A single `POST /api/v1/hr-generated-documents`
both creates and renders a document in one step ("generate"), gated by
`hr_generated_documents.generate` rather than `.create` (seeded for
forward compatibility, not yet wired to a route — same posture as the
existing unused `audit.export`).

Template content is plain text with a strict allowlist of `{{...}}`
placeholders (`employee.name`, `employee.employee_number`, `employee.email`,
`employee.department`, `employee.position`, `employee.location`,
`employee.employment_type`, `employee.start_date`, `tenant.name`, `today`),
substituted via `PlaceholderRenderer` using PHP's `strtr()` — a single,
non-recursive pass over a fixed map, never Blade compilation, `eval`, or
reflection-driven property access. An unknown token is left completely
unchanged rather than erroring or executing. See `docs/architecture.md`
and `docs/security.md` for the full design.

## PDF Export for HR Documents (Checkpoint 35)

A dependency/environment review (comparing `barryvdh/laravel-dompdf`,
`dompdf/dompdf`, `mpdf/mpdf`, `spatie/browsershot`, wkhtmltopdf, and
headless Chrome against Windows/Laragon, GitHub Actions, and cheap
shared-hosting constraints) recommended, and you approved, `dompdf/dompdf`
(direct, not the Laravel wrapper) with **Option B: generate the PDF on
demand, never store it**. `GET /api/v1/hr-generated-documents/{id}/download-pdf`
renders `rendered_content` into PDF bytes and streams them back —
nothing is written to any disk, so there's no new storage path to
secure and no file lifecycle to manage. Gated by the same
`hr_generated_documents.view` permission the JSON `show` route already
uses (downloading a PDF of a document you can already view isn't a new
capability). See `docs/architecture.md` and `docs/security.md` for the
full design and why headless-browser PDF generation was ruled out.

## HR Document Template Versioning Foundation (Checkpoint 36)

A new `hr_document_template_versions` table gives HR document templates
real version history, mirroring the `Policy`/`PolicyVersion` pattern
from Checkpoint 20: `hr_document_templates` stays catalogue metadata
(title, description, document type, status, `current_version_id`) and
`content_template` moved entirely into the version table — approved
after the gap analysis specifically to avoid a "which is authoritative"
question between a template and its own content. A migration backfills
every existing template with a published "version 1" (and backfills
every existing generated document's version reference to match — both
accurate, not guessed, since before this checkpoint a template only
ever had one live `content_template`). Generation now resolves a
template's *current published version* and records
`hr_document_template_version_id` on the generated document;
`rendered_content` remains the actual historical record, so PDF export
and existing generated documents are entirely unaffected. See
`docs/architecture.md` and `docs/security.md` for the full design.

## HR Document Approval Workflow Foundation (Checkpoint 37)

Generated HR documents now go through a real single-approver workflow —
`draft → pending_approval → approved | rejected`, with `archived`
reachable from any non-terminal state and `rejected → pending_approval`
(resubmit) closing the loop. Centralized in
`HrGeneratedDocumentStatus::canTransitionTo()`, the same pattern
`LifecycleProcessStatus` established in Checkpoint 33 — every
submit/approve/reject/archive action checks it server-side, never
inferred from which endpoint was called. Three new permissions
(`hr_generated_documents.{submit,approve,reject}`) keep HR Officer able
to generate/submit without ever self-approving. A PDF download is
allowed at any status (a real, useful preview step), but a plain-text
banner ("DRAFT — NOT YET SUBMITTED", "PENDING APPROVAL", etc.) is added
to anything that isn't `approved`, so an unapproved letter is never
mistaken for a final one. A migration backfills every pre-existing
`generated` document to `approved` (the closest accurate reading of
"already finalized" under the old content-only model). See
`docs/architecture.md` and `docs/security.md` for the full design.

## HR Document Template Library & Starter Templates (Checkpoint 38)

Eight starter HR document templates (Employment Confirmation, Offer,
Promotion, Warning, Exit/Offboarding, Reference, Contractor Engagement,
Probation Completion) are now seeded for the `uesl` demo tenant, each
with a real published version 1 using only the approved placeholder
tokens and generic, professional wording — no schema changes, no
global library table (Option A, approved): a starter template is just
a normal, tenant-owned `HrDocumentTemplate` row, indistinguishable from
one HR creates by hand. A new `POST /api/v1/hr-document-templates/{id}/duplicate`
endpoint (reusing the existing `hr_document_templates.create`
permission — no new permission) copies a template's metadata and its
current published version's wording into a new template, immediately
published, with a unique `"(Copy)"`/`"(Copy 2)"`-suffixed title and
slug — the same single-step create-with-version-1 flow every template
creation already follows. See `docs/architecture.md` and
`docs/security.md` for the full design.

## Recruitment & Applicant Tracking Foundation (Checkpoint 39)

A simple internal ATS foundation: `recruitment_jobs` (job openings,
with a `draft/open/on_hold/closed/cancelled` status lifecycle mirroring
`LifecycleProcessStatus`'s transition-guard shape), `recruitment_applicants`
(person identity), `recruitment_applications` (a person's application to
one job, with an `applied → screening → interview → offer → hired`/
`rejected`/`withdrawn` pipeline stage), and `recruitment_application_notes`
(internal-only recruiter notes). Applicant and application are created
together in one request — same single-step pattern as HR document
template creation. New split permissions: `job_openings.*` and
`job_applications.*` (`.view`/`.create`/`.update`/`.delete` plus the
narrower `.update_stage`/`.add_note`/`.mark_ready_for_conversion`), so a
role can move the pipeline forward or add notes without holding general
edit rights. A `ready_for_conversion` flag exists as a milestone marker
only — **no employee record is ever created automatically**; the
candidate-to-employee conversion flow itself is deliberately deferred
(see `docs/architecture.md`). No AI screening, job-board publishing,
email automation, offer automation, e-signature, or bulk import this
checkpoint. See `docs/architecture.md` and `docs/security.md` for the
full design.

## Candidate-to-Employee Conversion Foundation (Checkpoint 40)

`POST /api/v1/job-applications/{id}/convert-to-employee` turns an
eligible application into a real `Employee` row — eligible meaning
`stage: hired` **and** `ready_for_conversion: true` **and** not already
converted (all three re-checked server-side, not just implied by the
frontend). Gated by one new, deliberately narrow permission,
`job_applications.convert_to_employee` (Tenant Admin/HR Director/HR
Manager only — not HR Officer by default), and safe field mapping:
`first_name`/`last_name` come from the applicant, `department_id`/
`position_id`/`location_id`/`employment_type` pre-fill from the job
opening when present, and `employee_number`/`start_date`/`work_email`
are validated with the exact same uniqueness rules as a normal employee
create. The whole thing runs inside a database transaction — a
uniqueness collision rolls back cleanly, leaving no half-converted
application and no orphaned employee row. `converted_employee_id`/
`converted_at`/`converted_by` on `recruitment_applications` are always
server-set, never accepted from request input, and the application
itself is never deleted or overwritten — the conversion is additive
history, not a replacement. **No user account, role assignment, or
onboarding process is started automatically** — the Application detail
page shows a "Start onboarding" link to the existing Lifecycle Create
form after conversion, but nothing kicks off on its own. See
`docs/architecture.md` and `docs/security.md` for the full design.

## Recruitment-to-Onboarding Handoff Foundation (Checkpoint 41)

`POST /api/v1/job-applications/{id}/start-onboarding` closes the gap
Checkpoint 40 deliberately left open: turning a converted application's
"Start onboarding" link into a real, tracked handoff instead of just a
pre-filled route to the existing Lifecycle Create form. The endpoint
takes no request body at all — `employee_id`, `type: onboarding`, and
`status: draft` are entirely server-derived from the application's own
`converted_employee_id`, never accepted from input. Eligible only when
the application has already been converted, hasn't started onboarding
before, and the converted employee has no other active (draft/
in-progress) onboarding process — a prior *completed* or *cancelled*
one doesn't block a new one. Gated by `lifecycle.create` — reused, not
a new recruitment-specific permission, since starting onboarding is a
lifecycle action, not just a recruitment one. Fixed an existing gap
surfaced by this checkpoint: HR Director held
`job_applications.convert_to_employee` but zero `lifecycle.*`
permissions, meaning it could convert a candidate but never start
their onboarding — now gets the same full lifecycle grant HR Manager
already has. Runs in a transaction (the `LifecycleProcess` row and the
application's new `onboarding_process_id` link succeed or fail
together) and writes two audit log entries, mirroring Checkpoint 40's
"one entry per resource touched" pattern. **Still deliberately creates
only the process record itself — no tasks, no user account, no role
assignment, no notifications.** See `docs/architecture.md` and
`docs/security.md` for the full design.

## Onboarding & Offboarding Task Templates Foundation (Checkpoint 42)

Closes the gap Checkpoint 41 itself flagged as future work: starting an
onboarding (or offboarding) process created a completely bare
`LifecycleProcess` — zero tasks, every one added by hand. A new
tenant-owned `lifecycle_task_templates` catalog (its own admin page,
`/settings/lifecycle-task-templates`, gated by a new
`lifecycle_task_templates.{view,create,update,delete}` permission
group) lets HR define default tasks per process type — title,
optional description, an optional "due N days after the process
starts" offset, and a sort order. Whenever a process is created —
either directly (`POST /api/v1/lifecycle-processes`) or via the
recruitment handoff (`POST /job-applications/{id}/start-onboarding`,
Checkpoint 41) — every active template matching that process's own
tenant and type is copied into a real `LifecycleTask` row via the new
`LifecycleTaskTemplateApplier` service. Generated tasks are completely
independent of their template from that moment on (editing or
archiving a template later never touches tasks already created from
it — no live link is kept, on purpose), and nothing is assigned to
anyone automatically, since a template can't know who should get a
task. Both process-creation endpoints now run inside a database
transaction (new for the direct-create endpoint) so a process and its
template-derived tasks succeed or fail together. Nine starter templates
(five onboarding, four offboarding) are seeded for the `uesl` demo
tenant. **Still deliberately out of scope: task templates carry no
assignee, no notifications, and no traceability back to the template
that generated a given task.** See `docs/architecture.md` and
`docs/security.md` for the full design.

## User Account Provisioning (Checkpoint 43)

Closes a gap that predates this app's entire recruitment/lifecycle
chain: `users.create` was seeded back in Checkpoint 23 for forward
compatibility but never wired to a route — until now, a User account
could only ever be made via `UserSeeder`/tinker. `POST /api/v1/users`
is the first real user-creation endpoint, gated by `users.create` alone.
It always requires `role_id` (a tenant role, never a platform role,
independently re-checked by `User::assignRole()`) and accepts an
optional `employee_id` to link the new account to an existing,
unlinked, non-terminated employee in the same request — creation, role
assignment, and the optional link all happen inside one transaction.
**Deliberately a separate, explicit action, not automatic** — per your
approved scope choice, it is never triggered by candidate-to-employee
conversion (Checkpoint 40) or onboarding start (Checkpoint 41); an HR
Manager reaches it on purpose, either from Settings > Access > Users or
from an Employee's own detail page (a new "Create user account"
link, shown only when that employee has no linked user yet). **There is
still no password-reset or invite-email flow** — the caller sets the
account's real initial password directly in the form (confirmed by a
second field), and it is never returned in the response or written to
the audit log. HR Manager is granted `users.create` alongside the
`roles.view` its own create-user form's role picker requires — the same
"a real gap found while building the form" pattern already used for
`employees.view`/`document_categories.view` in earlier checkpoints. See
`docs/architecture.md` and `docs/security.md` for the full design.

## Password Reset (Checkpoint 44)

Closes the single biggest gap Checkpoint 43 itself flagged: there was
no way for any user — including one just created via `POST /api/v1/users`
— to set or reset their own password. Adds a real forgot-password flow
(`GET`/`POST /forgot-password`, `GET`/`POST /reset-password`, alongside
`login`/`logout` in `routes/auth.php`, not under `/api/v1`) built on the
`password_reset_tokens` table that's existed unused since Laravel's
default scaffold — and, in the process, the first real email this app
sends (`Illuminate\Auth\Notifications\ResetPassword`). **Never reveals
whether an email exists or which tenant it belongs to**: every response
is identical regardless of outcome, the same non-enumerating posture
`LoginRequest` already established for bad credentials. The tenant
boundary is enforced twice, independently — once when deciding whether
to actually send a link, once again when a token is redeemed — the same
platform-admin-vs-tenant check `LoginRequest`/`EnsureTenantMatchesAuthenticatedUser`
already perform, duplicated rather than shared across four call sites
now. The emailed link itself is tenant-aware (`AppServiceProvider::boot()`
builds it against the target user's own subdomain, never the requesting
host), which is what makes the *matched* case actually land somewhere
useful. Sends synchronously — deliberately not the first checkpoint to
introduce a queued job, since nothing in this app queues anything yet.
**Still no invite-email flow** — Checkpoint 43's create action still
requires the admin to set a real initial password directly; this
checkpoint only adds a way to change it afterward. See
`docs/architecture.md` and `docs/security.md` for the full design.

## Lifecycle Task Ordering & Reminders (Checkpoint 45)

Two independent gaps closed together, both flagged as future work back
in Checkpoint 33/42: tasks had no ordering at all beyond insertion
order, and nothing ever reminded anyone a task was due or overdue.

**Ordering** — a new `sort_order` column on `employee_lifecycle_tasks`.
A task generated from a `LifecycleTaskTemplate` copies the template's
own `sort_order` at generation time (the same "generate once, then
independent" posture as its title/description/due-date — editing a
template's order later never reaches back into tasks already created
from it). A manually-added task is appended to the end of its
process's existing list, never defaulted to the front. The full list
can be bulk-reordered via `POST /api/v1/lifecycle-processes/{id}/tasks/reorder`,
gated by `lifecycle.update` (reused, not a new permission — reordering
is a sub-action of editing the process, the same reasoning already
applied to every other narrow write action in this app), which requires
the *complete* current set of task IDs in the desired order — a partial
list is rejected, so there's never ambiguity about where an omitted
task lands. The Lifecycle process page now supports native
drag-and-drop reordering (no new frontend dependency) for anyone
holding that permission, on a non-terminal process.

**Reminders** — the app's first scheduled task
(`lifecycle:send-task-digest`, registered via `bootstrap/app.php`'s new
`->withSchedule()`, running daily at 07:00). For every active tenant,
it finds every pending/in-progress task that's overdue or due within
3 days, groups them by assignee, and sends each assignee a single
digest email (`App\Notifications\LifecycleTaskDigestNotification`) —
never one email per task. Deliberately sent synchronously, not queued:
queuing it would mean standing up a second new piece of always-on
infrastructure (a persistent `queue:work` process) in the same
checkpoint as the first (the scheduler's cron entry) — see
`docs/deployment.md` §6 for why that's a deliberately deferred
follow-up. Tasks with no assignee, or an assignee who's since been
deactivated, are silently skipped. **Still no per-tenant timezone for
the digest's send time, no in-app notification center, and no digest
suppression/snooze** — every eligible assignee gets the same email at
the same wall-clock moment, every day their tasks remain overdue or
due-soon. See `docs/architecture.md`, `docs/security.md`, and
`docs/deployment.md` for the full design.

## Invite-Email Flow for New Accounts (Checkpoint 46)

Closes the gap repeatedly flagged as "the single biggest remaining
gap" since Checkpoint 43: `POST /api/v1/users` no longer *always*
requires the caller to set a real password and share it out of band.
`send_invite` is now a required field on account creation — your
approved scope choice was "admin's choice," not "invite-only," so both
paths remain fully supported. `send_invite: false` is byte-for-byte
Checkpoint 43's original behavior (the caller sets and confirms a real
password directly). `send_invite: true` (the Create User form's
default) instead creates the account with an unusable random password
and emails the new user a link to set their own — reusing Checkpoint
44's exact `/reset-password/{token}` page and validation, just with new
welcome-style wording (`App\Notifications\UserInvited`) rather than a
reuse of Laravel's built-in `ResetPassword` notification, which would
have been a confusing thing to send someone who never had a password to
"reset." Submitting a password alongside `send_invite: true` is
rejected outright — the two paths are mutually exclusive, never a
priority order. No new permission was introduced; both paths remain
gated by the same `users.create` permission Checkpoint 43 already
established. **Still no "resend invite" action for an expired link, and
no visual indicator distinguishing a pending-invite account from one
with a real password** — see `docs/security.md` for the full design and
remaining gaps.

## Module Registry & Branding Foundation (Checkpoint 47)

Introduces two independent, backend-defined tenant configuration
surfaces: **module toggling** (a Tenant Admin can disable
`recruitment`, `lifecycle`, `leave`, `documents`, `policies`, or
`hr_documents` for their own tenant) and **branding** (a display name,
logo, and two brand colors, shown wherever the tenant's identity
appears in the UI). Neither is a general-purpose "app builder" — the
module list and the branding fields are both a fixed, backend-owned
enum/table, never free text or arbitrary keys from the frontend. See
[`architecture.md`](docs/architecture.md#module-registry--branding-foundation-checkpoint-47)
for the technical design and
[`security.md`](docs/security.md#module-registry--branding-foundation-checkpoint-47)
for the security model.

**Module disablement blocks the backend, not just the UI.** A
disabled module's routes return `403 {"reason": "module_disabled"}` —
the same enforcement whether the request comes from this app's own
frontend, a script, or a stray bookmark to an old URL. Disabling a
module never deletes, archives, or otherwise touches its business
data; re-enabling it makes everything reappear exactly as it was.
Core modules (`employees`, `settings`, `users_access`, `audit_logs`,
`dashboard`, `manager_hierarchy`, `password_reset`,
`account_invites`) can never be disabled — the backend rejects an
attempt with `422`, not silently ignoring it.

**Branding is deliberately narrow for this checkpoint**: tenant
display name, a logo (PNG/JPG/JPEG only — no SVG), and two hex
colors. No custom CSS/HTML/JS, no custom domains, no email-template
branding, no theme builder — see `architecture.md`'s "Future" note
for what's deliberately deferred.

## Documentation

- [`docs/platform-vision.md`](docs/platform-vision.md) — the long-term product/architecture vision (platform kernel, module layer, subscription/entitlement model, event-driven architecture, AI governance) that every checkpoint's design should be checked against. Not tied to a checkpoint; update it when the vision changes, not when a feature ships.
- [`docs/architecture.md`](docs/architecture.md) — multi-tenancy, tenant resolution, RBAC overview, internal-vs-public IDs, frontend architecture.
- [`docs/database.md`](docs/database.md) — schema conventions and table reference.
- [`docs/security.md`](docs/security.md) — authentication, RBAC design, local demo credentials, frontend security model, known limitations.
- [`docs/api.md`](docs/api.md) — `/api/v1` endpoint reference.
- [`docs/testing.md`](docs/testing.md) — testing conventions and patterns.
- [`docs/demo-guide.md`](docs/demo-guide.md) — demo users, login sequence, per-module demo flow, what each role should see, known limitations.
- [`docs/deployment.md`](docs/deployment.md) — environment configuration, tenant/subdomain deployment, storage/logging/queue readiness, build & verification commands, deployment smoke test checklist.
- [`docs/production-readiness.md`](docs/production-readiness.md) — production go/no-go checklist and security hardening checklist.
- [`docs/quality-gate.md`](docs/quality-gate.md) — local quality gate commands, CI reference, and the manual post-CI smoke test checklist.

## Project Standards

See `PeopleOS Master Development Constitution` and related standards
documents (security, database, API, QA, Git, AI governance) for the rules
governing how this codebase is built. Development proceeds checkpoint by
checkpoint — no major feature is added without explicit scope agreement.
