# Deployment Guide

**Checkpoint 27.** This is the practical "how to set up and deploy"
companion to `docs/security.md`/`docs/architecture.md` — environment
configuration, tenant/subdomain mechanics, storage, logging, and the
commands to run at each stage. See `docs/production-readiness.md` for
the go/no-go checklist, and `docs/demo-guide.md` for how to run a demo
once the app is up.

## 1. Local / Demo Setup

This project's actual local setup is Windows + Laragon, with
project-scoped PHP wrapper scripts — see `README.md` → "Local
Development Setup (Windows / Laragon)" for the full first-time detail
(PHP extensions, `php.ini` scoping, Apache vhost/SSL). Once that
one-time setup is done, bringing the app up from a fresh clone is:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Then edit `.env` for your database (`DB_DATABASE=peopleos_dev` by
default) and set `SEED_USER_PASSWORD` to any local value (never a real
credential — see `docs/security.md`). Then:

```bash
./artisan.bat migrate:fresh --seed
npm run build
```

`migrate:fresh --seed` is **destructive** — see "Demo Reset" below
before ever running it against anything other than your own local
database. For local iterative development, `npm run dev` (hot-reloading)
is more useful than `npm run build`; the app itself is served by your
Laragon/Apache vhost, not `php artisan serve` — see `README.md` for why
`artisan serve` alone can't handle this app's subdomain-per-tenant
routing without also configuring vhost/DNS for each subdomain.

If you genuinely have no existing Laragon/Apache vhost and just want a
single-tenant smoke check, `php artisan serve` will work for the base
domain (`peopleos.test`, i.e. Platform Super Admin / non-tenant routes)
but **not** for any `{tenant}.peopleos.test` route, since PHP's built-in
server doesn't do virtual-host/subdomain routing — see "Tenant/Subdomain
Deployment" below.

## 2. Verification Commands

Run these before considering any checkpoint (or any deployment) done —
this is the same set every checkpoint in this project has run:

```bash
./artisan.bat test              # full backend suite (in-memory SQLite — see docs/testing.md)
vendor/bin/pint --test          # code style, no changes
npx tsc --noEmit                # TypeScript, no emit
npm run build                   # production frontend build
php artisan route:list          # sanity-check the full route table
./artisan.bat route:audit-tenant-scoping   # see below
```

**`route:audit-tenant-scoping`** (Checkpoint 27) formalizes a check
that used to be a hand-run scratch script before every prior checkpoint
(see `docs/testing.md`): it inspects the real, registered route table
and confirms every `auth`-protected route also carries
`tenant.matches`. Read-only, safe to run any time, and now a real
regression test (`AuditTenantRouteScopingCommandTest`) rather than
something that only ever existed outside the repository.

None of the above proves the app behaves correctly against real
PostgreSQL over real HTTPS with real subdomain resolution — the test
suite runs on in-memory SQLite for speed (see `docs/testing.md`). A
live HTTPS smoke test against the actual running app (this project's
established practice every checkpoint) is what verifies that. See
"Deployment Smoke Test Checklist" below.

**All five commands above now also run automatically** via
`.github/workflows/ci.yml` (Checkpoint 29) on every push/PR, plus
`composer run quality` / `npm run quality` as the near-one-command
local equivalents. See `docs/quality-gate.md` for the full local/CI
reference — CI still does not replace the live smoke test below.

**This repository lives on GitHub Free** (Checkpoint 30 — a confirmed
business constraint, see `docs/quality-gate.md` §5) — a single
lightweight CI job, well within the 2,000 Actions minutes/month free
tier gives private repos. GitHub itself is source/docs/CI only, never
a production dependency — see "File Storage Readiness" and "Backup /
Restore Basics" below for where real data actually lives.

## 3. Tenant / Subdomain Deployment

**Local/demo**, tenant resolution is subdomain-based
(`ResolveTenant` middleware, `config('tenancy.base_domain')`, driven by
the `APP_DOMAIN` env var — see `config/tenancy.php`):

| Local URL | Resolves to |
|---|---|
| `https://uesl.peopleos.test` | UESL tenant |
| `https://airpeace.peopleos.test` | Air Peace tenant |
| `https://ibom.peopleos.test` | Ibom Air tenant |
| `https://peopleos.test` (base domain, no subdomain) | Platform Super Admin only — no tenant context |

This works locally because Laragon's local DNS/hosts resolve
`*.peopleos.test` to the local machine, and a locally-trusted cert
(mkcert-style) covers the wildcard.

**Production equivalent** — every one of these needs a real-world
counterpart, not just an environment variable change:

- **Wildcard DNS**: a real registered domain with a wildcard `A`/`CNAME`
  record (`*.yourdomain.com`) pointing at the server/load balancer —
  without it, only explicitly-registered subdomains would resolve, and
  new tenants couldn't be onboarded without a DNS change per tenant.
- **Web server virtual host**: the production web server (Apache/Nginx)
  needs a single vhost matching `*.yourdomain.com` (or explicit
  per-tenant vhosts, if wildcard isn't supported by the hosting
  platform) routing to the same Laravel `public/` document root —
  tenant resolution itself is application-level (`ResolveTenant`), not
  a web-server-level routing decision.
- **HTTPS certificates**: a real wildcard TLS certificate
  (`*.yourdomain.com`) or an automated per-subdomain issuance process
  (e.g. Let's Encrypt with DNS-01 challenge, since HTTP-01 can't
  practically cover an unbounded set of tenant subdomains). HTTPS is
  not optional — see `docs/production-readiness.md`.
- **Session domain behavior**: `SESSION_DOMAIN` must be set to the
  production wildcard cookie domain (leading dot, e.g.
  `.yourdomain.com`), the same mechanism already in local `.env`
  (`.peopleos.test`) — this is what lets a session cookie set on one
  subdomain remain valid (cookie-wise) when the browser later requests
  a different subdomain. It does **not** mean the session is
  *authorized* across tenants — see next point.
- **`tenant.matches` requirement**: every authenticated, tenant-scoped
  route must carry the `tenant.matches` middleware
  (`EnsureTenantMatchesAuthenticatedUser`) — this is what actually
  rejects a session cookie that's cookie-valid across the wildcard
  domain but belongs to a *different* tenant than the one the request
  arrived at. `SESSION_DOMAIN` makes the cookie reachable across
  subdomains; `tenant.matches` is what makes that safe. Verified by
  `./artisan.bat route:audit-tenant-scoping` (Checkpoint 27) and by
  live cross-tenant-session smoke testing (every checkpoint since
  Checkpoint 11).
- **Cross-tenant session protection**: confirmed, not assumed — every
  checkpoint's live smoke test includes reusing one tenant's session
  cookie against a different tenant's subdomain and confirming a clean
  `403`. This remains a required step in every future deployment/
  release smoke test, not just a one-time check. See
  `docs/production-readiness.md`.

## 4. File Storage Readiness

Uploaded employee documents are stored on the `local` filesystem disk
(`config/filesystems.php` → `'local' => ['root' => storage_path('app/private')]`)
— genuinely private storage, **not** the `public` disk or its
`public/storage` symlink. No code path in this app ever writes to the
`public` disk (confirmed by grep — zero references to
`Storage::disk('public')` anywhere in `app/`).

- **Why not public storage**: a file under `public/storage` is
  reachable by a direct URL guess with zero authorization — the entire
  point of `EmployeeDocumentController`'s download flow (permission
  check, tenant check, sensitive-document gating, audit logging) would
  be bypassable. Private documents must only ever be served through the
  authenticated, permission-checked download endpoint, never a static
  file URL.
- **Required filesystem permissions**: the web server process needs
  read/write access to `storage/app/private` (and the rest of
  `storage/`) but that directory must **not** be inside the web root
  (`public/`) and must **not** have a symlink exposing it — this is
  already the case (`storage/app/private` is outside `public/`).
  Standard Laravel deployment permissions apply: the web server user
  needs write access to `storage/` and `bootstrap/cache/`.
- **Backup considerations**: back up `storage/app/private` (the actual
  document files) alongside the database — a database backup alone
  restores document *metadata* (`employee_documents` rows: title,
  category, checksum, path) with no corresponding file content. Back up
  both together, or at least on the same schedule, so a restore doesn't
  produce metadata pointing at files that no longer exist.
- **Restore considerations**: restoring the database without restoring
  `storage/app/private` (or vice versa) leaves the two out of sync —
  document download would `404`/error even though the database row
  exists. Restore both from the same backup point together.
- **How seeded fake/demo files work**: `DemoDataSeeder` (Checkpoint 26)
  writes small fake text content (not real PDFs) directly to the
  `local` disk via `Storage::disk('local')->put(...)`, the same safe
  pattern `EmployeeDocumentFactory` already used in tests — there is no
  real file to "open," only metadata (title, category, sensitivity,
  expiry) to demo. See `docs/demo-guide.md`.
- **No public URLs for private documents**: confirmed — `local` disk
  has no `url` key configured (unlike the unused `public` disk, which
  does), so there is no `Storage::url()` path available for it even by
  accident.

## 5. Logging and Error Exposure

- **Production must not expose stack traces**: this depends entirely
  on `APP_DEBUG=false` (see `.env.example` and `config/app.php` —
  `'debug' => (bool) env('APP_DEBUG', false)`, defaulting closed if
  unset). With `APP_DEBUG=true`, Laravel's error pages/JSON responses
  include full stack traces, file paths, and query bindings — acceptable
  locally, never in production.
- **`bootstrap/app.php`'s `shouldRenderJsonWhen`** (Checkpoint 16)
  ensures `/api/*` always gets a JSON error response regardless of
  `Accept` headers — this is about response *format* consistency, not
  error detail; detail exposure is controlled by `APP_DEBUG` alone.
- **Logs must be protected**: `storage/logs/` is outside `public/` (same
  reasoning as document storage above) — never symlink or serve it.
  Standard file-permission hardening applies (web server user only,
  not world-readable).
- **Audit logs are a separate system from application logs, on
  purpose**: `storage/logs/laravel.log` (via `LOG_CHANNEL`) records
  application/error events for operators; the `audit_logs` table
  (`AuditLogger`, Checkpoint 4/5) records business-security events
  (who did what, when, to what) for compliance/security review, viewed
  through the read-only Audit Log UI (Checkpoint 24). Neither
  substitutes for the other — a masked audit log entry doesn't mean an
  application log line describing the same request is safe to leave
  unredacted, and vice versa.
- **Sensitive values must not be logged**: Laravel's default exception
  handling doesn't log request body/session content verbatim, and no
  code in this app explicitly logs full request payloads. `AuditLogger`
  masks known-sensitive key patterns in `old_values`/`new_values` at
  write time, and `AuditValueSanitizer` (Checkpoint 24) applies a
  broader mask at read time — but see the next point.
- **Audit masking is not a substitute for careful logging elsewhere**:
  `AuditValueSanitizer`'s pattern-matching (masking keys containing
  `password`, `token`, `secret`, `salary`, etc.) only covers the audit
  log's own `metadata`/`old_values`/`new_values` columns. It says
  nothing about what a future feature might write to
  `storage/logs/laravel.log` via `Log::info()`/`report()` — anyone
  adding new logging calls must independently avoid logging raw
  request bodies, full user objects, or any field this project already
  treats as sensitive (see the pattern list in `AuditValueSanitizer`
  for what's already considered sensitive here).

## 6. Queue / Cache / Session Readiness

- **Queues are configured but still not used** — `QUEUE_CONNECTION=database`
  is set, but nothing in this app implements `ShouldQueue` or calls
  `::dispatch()` anywhere (confirmed by grep across `app/`), including
  Checkpoint 45's new digest email (see below) — it's sent synchronously
  from within the scheduled command that generates it, deliberately, not
  queued. Every operation (audit logging, leave approval, document
  upload/download, policy publish/assign/acknowledge, the digest email)
  runs synchronously. This is fine at current scale; revisit only once a
  genuinely slow or fire-and-forget operation needs one — don't
  introduce Redis or a `queue:work`/supervisor process before there's an
  actual queued job needing one.
- **Scheduler: exactly one task exists as of Checkpoint 45** —
  `lifecycle:send-task-digest` (`App\Console\Commands\SendLifecycleTaskDigest`),
  registered via `bootstrap/app.php`'s `->withSchedule()`, running daily
  at 07:00 server time. Every checkpoint before this one left that
  closure absent entirely (`routes/console.php` was the default Laravel
  skeleton, one `inspire` command, nothing registered via `Schedule::`)
  — **this means production deployments must now add a real cron entry**,
  which was never required before:
  ```
  * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
  ```
  Without this cron entry, `lifecycle:send-task-digest` silently never
  runs and no lifecycle-task reminder email is ever sent — nothing else
  in the app depends on the scheduler yet, so this is easy to miss in a
  deployment that predates Checkpoint 45. `php artisan schedule:list`
  verifies what's registered; `php artisan schedule:run` can be invoked
  by hand to confirm the cron entry itself is correct.
- **Cache**: `CACHE_STORE=database` locally. Fine for current traffic
  (see `docs/security.md`'s note that `hasPermission()` isn't cached
  yet either). Production recommendation: `database` remains
  acceptable at small-to-medium scale; move to `redis`/`memcached`
  only if cache-table contention becomes measurable — don't add
  infrastructure preemptively.
- **Session**: `SESSION_DRIVER=database` locally, same recommendation
  as cache — acceptable in production as-is. The one setting that
  **must** change for production is `SESSION_SECURE_COOKIE=true` (see
  `.env.example`) so the session cookie is never sent over plain HTTP.

## 7. Backup / Restore Basics

- **Database**: standard PostgreSQL backup (`pg_dump`/managed
  provider snapshot) on whatever schedule your operational policy
  requires. This project introduces no special backup requirement
  beyond "back up the database" — no non-standard storage engine, no
  data held only in cache.
- **Files**: back up `storage/app/private` alongside the database (see
  "File Storage Readiness" above) — they must be restored together to
  stay consistent.
- **`.env` / `APP_KEY`**: back up the production `.env` file itself
  (or the secrets it holds, via whatever secrets manager you use)
  separately from the database — losing `APP_KEY` makes every
  encrypted value (including existing session data) unreadable, and
  regenerating it does not recover old data, it just starts fresh.
- **Restore drill**: periodically test that a real restore (database +
  files) produces a working app, not just that a backup file exists.
  Not automated by this project — a manual operational practice to
  establish.

## 8. Deployment Smoke Test Checklist

After any deployment (or any `migrate:fresh --seed` in a demo
environment), confirm live, over real HTTPS:

1. Platform Super Admin logs in and sees a safe, non-tenant dashboard.
2. Tenant Admin logs in and reaches full tenant Settings.
3. HR Manager logs in and reaches Employees/Leave/Documents/Policies.
4. HR Officer logs in, sees Settings (per Checkpoint 26's nav-permission
   fix), cannot manage users/roles.
5. Line Manager logs in, can approve only direct-report leave.
6. Employee logs in, sees only self-service data, blocked from Settings.
7. Auditor logs in, can view the audit log, blocked from any admin
   write (e.g. tenant settings).
8. Cross-tenant session reuse is blocked (`403`) on both a web route and
   an `/api/v1` route.
9. A document upload then download round-trip works end-to-end.
10. A leave request → approval round-trip works, scoped correctly.
11. A policy publish → assign → acknowledge round-trip works.
12. The Audit Log view shows the actions performed in the steps above.
13. (Checkpoint 45+) The scheduler's cron entry is actually running —
    `php artisan schedule:list` shows `lifecycle:send-task-digest`, and
    a manual `php artisan schedule:run` (or waiting for the next
    07:00) delivers a real digest email for a tenant with an overdue
    task assigned.

This is the same checklist this project's checkpoints have run
piecemeal since Checkpoint 11 — see `docs/production-readiness.md` for
the broader go/no-go checklist this smoke test is one part of.
