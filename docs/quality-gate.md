# Quality Gate

**Checkpoint 29.** The automated counterpart to the manual checks this
project has run by hand before every prior checkpoint. See
`docs/testing.md` for testing conventions in depth, `docs/deployment.md`
for the full setup/deployment path, and `docs/production-readiness.md`
for the production go/no-go checklist — this file is specifically the
"what to run, and when" reference.

## 1. Local Quality Gate

Run these five commands before every commit (this is the exact set
this project has run manually every checkpoint):

**Windows / Laragon** (this project's actual local setup — see
`README.md` → "Local Development Setup"):

```bash
./artisan.bat test
vendor/bin/pint --test
./artisan.bat route:audit-tenant-scoping
npx tsc --noEmit
npm run build
```

**Linux / macOS equivalent:**

```bash
php artisan test
vendor/bin/pint --test
php artisan route:audit-tenant-scoping
npm run typecheck
npm run build
```

### Near-one-command version

Two composite scripts cover the same five checks — one for the backend
half, one for the frontend half (a single cross-platform script
spanning both PHP and Node conveniently isn't practical — see
`composer.json`/`package.json`):

```bash
composer run quality   # test -> pint --test -> route:audit-tenant-scoping
npm run quality         # typecheck (tsc --noEmit) -> build
```

Run both before every commit. `composer run quality` reuses the
existing `test` script (which also clears the config cache first) —
it isn't a second, parallel implementation of the same check.

### What each check catches, and what it doesn't

- **`php artisan test`** — the full backend suite (`tests/Unit`,
  `tests/Feature`), run against **in-memory SQLite**
  (`phpunit.xml`), not PostgreSQL. This is deliberate, for speed — see
  "Why the test suite runs on SQLite" below. Proves the app's own
  logic (permission checks, tenant filtering, audit logging, RBAC
  guards) is correct; does not prove PostgreSQL-specific behavior.
- **`vendor/bin/pint --test`** — code style only, changes nothing (add
  `--test` is what makes this non-destructive; without it, Pint
  auto-fixes). Catches formatting drift, not logic bugs.
- **`php artisan route:audit-tenant-scoping`** (Checkpoint 27) —
  inspects the real, registered route table and confirms every
  `auth`-protected route also carries `tenant.matches`. Read-only,
  changes nothing. Catches the one specific class of bug this project
  has cared about most since Checkpoint 13: a new authenticated,
  tenant-scoped route that forgot the middleware that actually
  enforces tenant isolation.
- **`tsc --noEmit`** (via `npx tsc --noEmit` or `npm run typecheck`) —
  TypeScript type-checking only; `vite build` does not fully
  type-check on its own, so this step exists specifically because a
  build succeeding is not proof the types are correct.
- **`npm run build`** — the actual production frontend build. Confirms
  the app compiles for real deployment, and (since Checkpoint 26) that
  the per-page lazy-loading bundle-size fix continues to hold (no
  "chunk larger than 500 kB" warning reappearing).

**None of the above proves the app behaves correctly against real
PostgreSQL, over real HTTPS, with real subdomain resolution and a real
browser session.** That's what the manual live smoke test (below) is
for — it has been, and remains, a required step this quality gate does
not replace.

## 2. CI (GitHub Actions)

`.github/workflows/ci.yml` runs the same five checks automatically on
every push/PR to `main`/`master`, plus the setup steps needed to get
there:

1. Checkout code.
2. Set up PHP 8.3 with `pdo_pgsql`/`pgsql` and the other extensions
   this app needs.
3. Set up Node (version pinned via `.nvmrc`).
4. `composer install` (PHP dependencies, including dev tools —
   PHPUnit, Pint).
5. `npm ci` (Node dependencies, from the committed lockfile —
   deterministic, unlike `npm install`).
6. Copy `.env.example` → `.env`, generate a fresh `APP_KEY`
   (`php artisan key:generate`) — a new key every CI run, never
   persisted or reused.
7. Run migrations against a real PostgreSQL service container.
8. Run the tenant-route audit against that same PostgreSQL-backed app
   boot.
9. Run the backend test suite (`php artisan test`) — this step uses
   `phpunit.xml`'s own in-memory SQLite override, **not** the
   PostgreSQL service from steps 7-8 — see below for why.
10. Run Pint, TypeScript check, and the frontend build.

### Why the test suite runs on SQLite, but CI still provisions PostgreSQL

This project's backend test suite has run on in-memory SQLite since
early in the project (`phpunit.xml`'s `<env name="DB_CONNECTION"
value="sqlite"/>`) — a deliberate speed tradeoff, not an oversight (see
`docs/testing.md`'s "Verifying against the real app" section for the
full reasoning, and every checkpoint's live-HTTPS-smoke-test step for
how PostgreSQL-specific and real-network behavior gets verified
separately). This checkpoint does **not** change that — doing so would
be changing established test behavior with no CI-specific problem
forcing it.

Instead, the CI workflow provisions a genuine PostgreSQL service
container (matching how this app is actually deployed and demoed) and
uses it for the one thing that needs a real, fully-booted app with a
real database connection: running migrations and then the tenant-route
audit against that real schema. `phpunit.xml`'s own `<env>` block still
takes effect during the `php artisan test` step regardless of what the
job's ambient `DB_*` environment variables say (the same way a local
`.env` pointing at `pgsql` doesn't change what `./artisan.bat test`
actually runs against) — so the two don't conflict, they just each do
their own job in the same workflow run.

### No secrets in CI

- `APP_KEY` is generated fresh every run (`php artisan key:generate`)
  — never committed, never reused across runs.
- The PostgreSQL service's credentials
  (`peopleos_ci` / `peopleos_ci_password` / database `peopleos_ci`) are
  throwaway, local to that one job run, torn down when it ends. They
  are not real credentials and don't need to be — see
  `docs/production-readiness.md` for why production credentials must
  still be strong and unique regardless.
- `SEED_USER_PASSWORD` in the CI `.env` is an obviously-fake placeholder
  string (`ci-only-not-a-real-password`), never the real local demo
  value from your own machine's `.env` — see `docs/security.md` "Local
  Demo Credentials."
- No middleware is disabled, no permission check is bypassed, no test
  is skipped beyond the one pre-existing, already-documented skip in
  `AuditLoggingTest` (unrelated to this checkpoint).

## 3. Manual Post-CI Smoke Test

CI does **not** attempt to automate the live HTTPS smoke test this
project has run every checkpoint — it depends on local subdomains
(`{tenant}.peopleos.test`), a locally-trusted TLS certificate, and real
browser/session behavior, none of which exist in a CI runner. This
remains a required manual step, run locally against the real app,
after CI passes and before considering a change fully verified:

1. Log in as each seeded demo role (Tenant Admin, HR Manager, HR
   Officer, Line Manager, Employee, Auditor — see `docs/demo-guide.md`)
   and confirm each sees the navigation/data their permissions allow.
2. Log in as Platform Super Admin (`super.admin@peopleos.test`, base
   domain) and confirm a safe, non-tenant experience.
3. Reuse one tenant's session cookie against a different tenant's
   subdomain and confirm a clean `403` on both a web route and an
   `/api/v1` route.
4. Complete one real document upload → download round-trip.
5. Complete one real leave request → approval round-trip, confirming a
   Line Manager can only approve a direct report.
6. Complete one real policy publish → assign → acknowledge round-trip.
7. Confirm the Audit Log view shows the actions from steps 3-6.

See `docs/deployment.md` §8 "Deployment Smoke Test Checklist" for the
full version of this list this section summarizes.

## 4. What CI Does Not Cover Yet

- The live HTTPS smoke test above (by design — see "Manual Post-CI
  Smoke Test").
- PostgreSQL-specific query behavior inside the actual PHPUnit suite
  (the suite itself runs on SQLite) — covered instead by the
  migration + route-audit steps running against the real PostgreSQL
  service, and by manual smoke testing.
- Any check of the demo data itself beyond migrations succeeding — CI
  does not run `DemoDataSeeder` (that's part of `db:seed`, not
  `migrate --force`); `DemoDataSeederTest` (Checkpoint 26) already
  covers this at the PHPUnit level, which does run in CI.
- Deployment to any real environment — this workflow only verifies
  quality gates on push/PR; it does not deploy anywhere.
- Load testing, accessibility auditing, dependency vulnerability
  scanning, or security static analysis — none of these exist yet in
  this project, CI or otherwise.

## 5. GitHub Free — Current Plan and When to Reconsider

**Business constraint (confirmed Checkpoint 30): PeopleOS will run on
GitHub Free for the next few years.** Nothing in this project's
CI/deployment/operations design should assume a paid GitHub plan.
Concretely, that means:

- **This repository is private, on GitHub Free.** Free private repos
  get unlimited collaborators, unlimited repos, Issues/PRs/Releases
  with no restriction, and **2,000 Actions minutes/month** (Linux
  runners, the only kind this workflow uses, count at the standard 1×
  rate — no multiplier).
- **GitHub is source-code/docs/CI infrastructure only, never
  production infrastructure.** No production files, uploaded employee
  documents, database backups, `.env` files, secrets, private
  certificates, or customer data are ever committed here — see
  `docs/deployment.md`/`docs/production-readiness.md` for where those
  actually live (a real database, real private storage, a real secrets
  manager — none of them GitHub). GitHub Actions provisions a fresh,
  throwaway PostgreSQL container per CI run for testing only; it is
  never a production database.
- **This workflow is already lightweight, deliberately:** a single
  `ubuntu-latest` job (no OS/PHP/Node version matrix), Composer and npm
  dependency caching (`actions/cache`, `actions/setup-node`'s built-in
  `cache: npm`) so unchanged dependencies aren't re-downloaded every
  run, and `concurrency: cancel-in-progress` so a quick follow-up push
  cancels the now-superseded run already in progress rather than
  letting both finish. A typical run (from the first real GitHub
  Actions execution, Checkpoint 30) takes roughly 1-2 minutes — at that
  rate, 2,000 minutes/month comfortably covers well over 1,000 CI runs,
  far more than a small team pushes in a month.
- **No paid-tier-only feature is used or assumed anywhere in this
  project**: no GitHub Enterprise/Team-only branch protection rules, no
  required-reviewers-with-code-owners enforcement, no self-hosted
  runners, no GitHub Packages/Container Registry usage, no Git LFS, no
  advanced security features (secret scanning push protection is
  actually free for public repos and available but not required here
  for private ones), no larger/GPU runners.

### When to reconsider a paid GitHub plan

None of these apply today — this is a forward-looking list, not a
current gap:

- **Actions minutes usage approaching 2,000/month.** Realistic
  triggers: significantly more contributors pushing frequently, a
  matrix build (multiple PHP/Node versions) added to CI, or new,
  slower jobs (e.g. a future end-to-end/browser test suite) added
  alongside this one. Check usage under
  `github.com/settings/billing` before it becomes a problem, not
  after a run gets throttled.
- **Needing more than one job's worth of parallelism regularly** (e.g.
  splitting backend/frontend checks into separate concurrent jobs to
  cut wall-clock time) — GitHub Free still allows this, but it burns
  minutes faster (multiple jobs running in parallel still each consume
  their own minutes), so watch the same budget.
- **Needing GitHub-hosted deployment targets** (Pages for a paid tier's
  custom domain/bandwidth needs, or GitHub-hosted container registry
  storage beyond the free allowance) — this project deploys elsewhere
  entirely (see `docs/deployment.md`), so this is unlikely to apply
  unless that changes.
- **Needing enterprise governance features** — SAML SSO, required
  code owner reviews with organization-wide enforcement, IP allow
  lists, or audit log retention beyond what Free provides — typically
  driven by a compliance requirement (e.g. SOC 2) rather than
  engineering need. Revisit if/when PeopleOS itself pursues a
  compliance certification that requires this of its own tooling.
- **Team size outsized for informal coordination** — GitHub Free
  already supports unlimited private-repo collaborators, so this is
  about process (needing enforced review policies, protected-branch
  rules beyond what Free supports), not a hard technical ceiling.

**For now: GitHub Free is confirmed sufficient**, and every CI/tooling
decision in this project is made to keep it that way.
