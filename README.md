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

## Documentation

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
