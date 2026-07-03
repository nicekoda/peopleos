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

See `docs/architecture.md`/`docs/security.md`/`docs/api.md` for the full
design, what's shared with the frontend (and what never is), and the
future module rollout plan.

## Documentation

- [`docs/architecture.md`](docs/architecture.md) — multi-tenancy, tenant resolution, RBAC overview, internal-vs-public IDs, frontend architecture.
- [`docs/database.md`](docs/database.md) — schema conventions and table reference.
- [`docs/security.md`](docs/security.md) — authentication, RBAC design, local demo credentials, frontend security model, known limitations.
- [`docs/api.md`](docs/api.md) — `/api/v1` endpoint reference.
- [`docs/testing.md`](docs/testing.md) — testing conventions and patterns.

## Project Standards

See `PeopleOS Master Development Constitution` and related standards
documents (security, database, API, QA, Git, AI governance) for the rules
governing how this codebase is built. Development proceeds checkpoint by
checkpoint — no major feature is added without explicit scope agreement.
