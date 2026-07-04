# Production Readiness Checklist

**Checkpoint 27.** A go/no-go checklist for taking PeopleOS from local/
demo to a real deployment. Each item links back to the detailed
reasoning in `docs/deployment.md`/`docs/security.md` — this file is
deliberately just the checklist, not the explanation.

This checklist describes what **should** be true before a production
deployment. It does not itself change any configuration — treat every
unchecked item as a real gap to close before going live, not as
optional polish.

## Environment & Transport

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` (never `true` — see `docs/deployment.md` §5)
- [ ] Real `APP_KEY`, generated via `php artisan key:generate` against
      the production environment itself, never copied from local/demo
- [ ] `APP_URL` is the real production HTTPS URL
- [ ] HTTPS enforced everywhere — no plain-HTTP path to the app at all
- [ ] Real registered domain with wildcard DNS (`*.yourdomain.com`)
      configured — see `docs/deployment.md` §3
- [ ] Wildcard (or automated per-subdomain) TLS certificate covering
      every tenant subdomain
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_DOMAIN` set to the real wildcard cookie domain
      (leading dot)
- [ ] If deployed behind a reverse proxy/load balancer that terminates
      TLS: trusted proxies configured (`bootstrap/app.php` —
      `Application::configure()->trustProxies(...)`, **not currently
      configured** in this codebase) so Laravel correctly detects the
      original request as HTTPS. See `docs/security.md` "Deployment &
      Production Hardening".

## Database & Credentials

- [ ] Strong, unique `DB_USERNAME`/`DB_PASSWORD` for the production
      database — never reused from local/demo
- [ ] Database backup schedule established and tested (see
      `docs/deployment.md` §7)
- [ ] Private file backup (`storage/app/private`) established on the
      same/compatible schedule as the database backup, so a restore
      keeps metadata and file content in sync

## Storage & Permissions

- [ ] `FILESYSTEM_DISK=local` (unchanged) — documents remain on private
      storage, never moved to the `public` disk
- [ ] `storage/` and `bootstrap/cache/` writable by the web server
      process, and **not** inside the public web root
- [ ] No symlink or web-server alias exposes `storage/app/private`

## Queue / Scheduler

- [ ] Explicit decision recorded: this app currently has no queued
      jobs and no scheduled tasks (see `docs/deployment.md` §6) — no
      queue worker or `schedule:run` cron entry is required yet. If a
      future checkpoint adds either, this item must be re-verified.

## Mail

- [ ] `MAIL_MAILER` points at a real transactional provider/SMTP relay
      (not `log`) if/when any feature actually sends email — currently
      no feature does, so this is a placeholder for future readiness,
      not a current gap.

## Logging & Audit

- [ ] `LOG_LEVEL` set appropriately for production noise (`info`/
      `warning`, not `debug`)
- [ ] Log files/directory not web-accessible, permissions restricted
      to the web server user
- [ ] Log retention policy defined (rotation/archival per your own
      operational standard — not automated by this project)
- [ ] Audit log (`audit_logs` table) retention policy defined
      separately from application log retention — audit logs are
      compliance/security records, not debug output (see
      `docs/deployment.md` §5)

## Admin & Demo Accounts

- [ ] Real admin account(s) created for actual production tenants —
      never reuse a demo password (`SEED_USER_PASSWORD`) for a real
      account
- [ ] Demo seeders (`UserSeeder`'s demo logins, `DemoDataSeeder`) are
      **not** run against production — `migrate:fresh --seed` must
      never be run against a production database (see
      `docs/demo-guide.md`'s warning). If a production deployment needs
      its *initial* Tenant/Permission/Role rows, run `TenantSeeder`/
      `PermissionSeeder`/`RoleSeeder` deliberately and individually —
      never the full `DatabaseSeeder` chain, which also runs
      `UserSeeder`'s demo logins and `DemoDataSeeder`'s fake data.
- [ ] Platform Super Admin account protected the same as any other
      privileged credential — strong password, and (once available)
      MFA; no MFA exists yet in this app (see "Do not build yet" list,
      unchanged this checkpoint).

## Smoke Tests (run against the real deployment before calling it live)

- [ ] **Tenant isolation** — a session cookie from one tenant, reused
      against a different tenant's subdomain, is rejected (`403`) on
      both a web route and an `/api/v1` route
- [ ] **Role/permission** — each seeded/real role sees exactly the
      navigation and data its permissions allow, confirmed for at
      least Tenant Admin, HR Manager/Officer, Line Manager, Employee,
      Auditor, and Platform Super Admin
- [ ] **Document upload/download** — a real upload, then a real
      authenticated download, round-trips correctly; a direct
      unauthenticated URL guess at the storage path fails
- [ ] **Policy acknowledgement** — publish → assign → acknowledge
      round-trips correctly end to end
- [ ] **Leave approval** — a Line Manager can approve a direct report's
      leave request and is rejected approving anyone outside their
      reporting line

See `docs/deployment.md` §8 for the full ordered smoke-test script this
checklist's last five items summarize.

## Security Hardening Checklist

This section is the security-specific subset of the above, gathered in
one place for a pre-launch security review:

- [ ] `APP_DEBUG=false` — no stack traces, file paths, or query
      bindings exposed in any response
- [ ] HTTPS-only, secure session cookie, correct `SESSION_DOMAIN`
- [ ] `tenant.matches` present on every `auth`-protected, tenant-scoped
      route — verified by `php artisan route:audit-tenant-scoping`
      (Checkpoint 27) and the corresponding test
      (`AuditTenantRouteScopingCommandTest`)
- [ ] Platform Super Admin cannot call any tenant-scoped `/api/v1`
      endpoint (confirmed `403` — established since Checkpoint 21/22,
      re-verified every checkpoint's live smoke test)
- [ ] Private document storage remains genuinely private — no `public`
      disk usage, no direct storage URL, download only through the
      permission-checked endpoint
- [ ] Audit logs remain append-only — no update/delete route exists
      (structurally verified by a route-table inspection test since
      Checkpoint 24) — and sensitive fields stay masked
      (`AuditValueSanitizer`)
- [ ] Sensitive Resource fields stay hidden (`password`,
      `remember_token`, `ip_address`, `user_agent`, etc. — see
      `docs/security.md`'s Resource-safety convention)
- [ ] Demo users/seeders never bypass real permission checks — every
      demo account (Checkpoint 26) holds exactly its role's normal
      permission set, nothing more
- [ ] No hardcoded bypass users, disabled middleware, or permissive
      demo-only routes exist anywhere in the codebase (confirmed by
      this checkpoint's review — none were found; none were introduced)
- [ ] Built-in/system roles (Tenant Admin, HR Manager, etc.) cannot be
      edited or have permissions added/removed through the RBAC
      management UI/API (Checkpoint 28 — confirmed `403` regardless of
      which permission the actor holds)
- [ ] Permission assignment/removal on custom roles is gated by
      `permissions.assign`, writes an audit log entry either way, and
      is never possible against a platform role or another tenant's
      role (Checkpoint 28)
- [ ] Full backend test suite passes (`./artisan.bat test`), code style
      is clean (`vendor/bin/pint --test`), and a full live HTTPS smoke
      test has been run since the last change to any of the above
