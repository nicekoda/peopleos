# PeopleOS Demo Guide

**Checkpoint 26** (updated Checkpoint 27 with a reset-command warning;
updated Checkpoint 32 with Departments/Positions/Locations admin;
updated Checkpoint 33 with Onboarding & Offboarding;
updated Checkpoint 34 with HR Documents & Letter Generation;
updated Checkpoint 35 with PDF download for generated HR documents;
updated Checkpoint 36 with HR document template version history;
updated Checkpoint 37 with HR document approval workflow;
updated Checkpoint 38 with HR document starter templates & duplication;
updated Checkpoint 39 with Recruitment & Applicant Tracking).
This is the practical "how to run a demo" companion to
`docs/security.md`/`docs/architecture.md` — it doesn't restate the RBAC
design or the tenant-isolation model, only how to log in, what to click,
and what each role should (and shouldn't) be able to do. All data below
lives on the `uesl` tenant, seeded by `DemoDataSeeder` (see
`database/seeders/DemoDataSeeder.php`).

## 1. Local Setup Reminder

See `README.md` → "Local Development Setup" for the full first-time
setup. Once the project is installed and `.env`/`SEED_USER_PASSWORD` are
configured, get a fresh demo database with:

```bash
./artisan.bat migrate:fresh --seed
```

> **⚠ Destructive command — local/demo environments only.**
> `migrate:fresh` drops every table and rebuilds the schema from
> scratch before seeding — **anything currently in that database is
> gone**, with no confirmation prompt and no recovery. Only ever run
> this against your own local development database
> (`DB_DATABASE=peopleos_dev` by default). **Never run this — or any
> `migrate:fresh` — against a production database.** See
> `docs/production-readiness.md` "Admin & Demo Accounts" for the
> production rule this backs: production seeding, if ever needed, runs
> `TenantSeeder`/`PermissionSeeder`/`RoleSeeder` individually, never the
> full `DatabaseSeeder` chain this command triggers.

This runs `TenantSeeder` → `PermissionSeeder` → `RoleSeeder` →
`UserSeeder` → `DemoDataSeeder`, in that order. `DemoDataSeeder` only
seeds the `uesl` tenant — `airpeace`/`ibom` keep their existing minimal
Tenant Admin-only setup, so the tenant count never grows past the three
already established.

## 2. Demo Users / Roles

**Local demo only — never real credentials.** Password for every demo
user is `SEED_USER_PASSWORD` from `.env` (not committed). Do not put an
actual password value in this file or any other committed document — see
`docs/security.md` → "Local Demo Credentials" for the same rule.

All UESL users log in at `https://uesl.peopleos.test/login`. The Platform
Super Admin logs in at the base domain, `https://peopleos.test/login`.

| Email | Role | What they're for in the demo |
|---|---|---|
| `admin@uesl.peopleos.test` | Tenant Admin | Full tenant control — company settings, users & roles, everything |
| `hr.manager@uesl.peopleos.test` | HR Manager | Employee/leave/document/policy management, day-to-day HR operations |
| `hr.officer@uesl.peopleos.test` | HR Officer | Leave approval, policy authoring, no user/role management |
| `line.manager@uesl.peopleos.test` | Line Manager | Approves leave for direct reports only — nothing tenant-wide |
| `employee@uesl.peopleos.test` | Employee | Self-service only — own profile, own leave, own documents |
| `auditor@uesl.peopleos.test` | Auditor | Read-only oversight — audit log, tenant-wide view, no writes |
| `super.admin@peopleos.test` | Platform Super Admin | Cross-tenant platform role, deliberately blocked from any single tenant's data |

Each of the first five is linked to a real seeded Employee record (except
the Auditor, who — like the Tenant Admin — is an account-level role, not
an operational employee, so intentionally has no linked Employee record):

| Employee | Linked login | Department | Position | Reports to |
|---|---|---|---|---|
| Ngozi Eze (EMP-90001) | `hr.manager@uesl...` | Human Resources | HR Manager | — |
| Aisha Bello (EMP-90002) | `hr.officer@uesl...` | Human Resources | HR Officer | Ngozi Eze |
| Tunde Adeyemi (EMP-90003) | `line.manager@uesl...` | Engineering | Engineering Line Manager | — |
| Chidi Okafor (EMP-90004) | `employee@uesl...` | Engineering | Software Engineer | Tunde Adeyemi |

Eight further employees (Femi Adisa, Grace Nwosu, Ibrahim Sule, Blessing
Okon, Emeka Nwachukwu, Fatima Yusuf, Segun Okafor, David Essien) round out
five departments (Engineering, Human Resources, Finance, Customer
Support, Operations) across three locations (Lagos HQ, Abuja Office,
Remote), with a full manager tree and one Inactive example (David Essien)
— 12 employees total, none of it excessive for a demo.

## 3. Suggested Demo Login Sequence

A natural order to show the app, each login building on the last:

1. **Tenant Admin** — company profile, full Users & Access, full Settings.
2. **HR Manager** — day-to-day operations: employees, leave, documents, policies.
3. **HR Officer** — the same modules with a narrower lens (no user/role management).
4. **Line Manager** — approves Chidi Okafor's pending leave request; is blocked from approving anyone outside their reporting line.
5. **Employee** (Chidi Okafor) — self-service only: own profile, own leave request, own documents.
6. **Auditor** — audit log, read-only everywhere else.
7. **Platform Super Admin** — logged in at the base domain, to show the deliberately different (safe, non-tenant) experience.

## 4. Demo Flow

- **Dashboard** — log in as HR Manager, show the permission-aware summary cards (pending leave, recent requests, document/policy counts). Log in as Employee afterward to show the same page rendering a much narrower, self-scoped set of cards.
- **Employees** — as HR Manager, open the Employees list, show department/position/manager columns, open Chidi Okafor's profile, show the linked-user badge.
- **Leave request and approval** — as Employee (Chidi Okafor), the pending Annual Leave request is already seeded; switch to Line Manager (Tunde Adeyemi) and approve it live. Then show Grace Nwosu's already-approved request and Ibrahim Sule's already-rejected request (with a rejection reason) as HR Manager/HR Officer.
- **Document upload/download** — as HR Manager or Employee, open Chidi Okafor's documents: a normal "Offer Letter", a sensitive "Employment Contract" (badge visible), and switch to Grace Nwosu/Ibrahim Sule's documents to show an expiry-dated certification and one expiring soon.
- **Policy publish/assign/acknowledge** — as HR Manager, show the Draft "Remote Work Policy" (not published), the Published-but-unassigned "Code of Conduct", and the published + assigned "Data Protection Policy" with a mix of pending and acknowledged rows.
- **Settings** — as Tenant Admin, tour the full hub (Company, Users & Access, Document Categories, Leave Types, Departments, Positions, Locations, Security & Audit) — every card now reflects real, working pages, not "Coming later" placeholders.
- **Departments/Positions/Locations** — as HR Manager, open Settings → Departments, edit one, archive another (status flips to Inactive, no hard delete); show the same for Positions and Locations. Then open the Employee edit form and show the three new dropdowns only ever offer active entities — an archived one silently disappears from the list, and the backend independently rejects it even if a stale ID were submitted directly.
- **Onboarding & Offboarding** — no demo data is pre-seeded for this module (deliberately, to keep the seed set small); create it live instead. As Tenant Admin or HR Manager, open an employee's profile and click "Start Onboarding," add a couple of tasks (e.g. "Set up laptop," assigned to the HR Manager demo user), then switch to that assigned user and show them completing their own task from `/lifecycle`. Switch to Line Manager (Tunde Adeyemi) and show they only ever see Chidi Okafor's (their direct report's) lifecycle processes, never an unrelated employee's.
- **HR Documents & Letter Generation** — 8 starter templates (Employment Confirmation, Offer, Promotion, Warning, Exit/Offboarding, Reference, Contractor Engagement, Probation Completion letters) are now pre-seeded (Checkpoint 38); generated documents themselves are still created live (same reasoning as Onboarding & Offboarding — keep the seed set small). As Tenant Admin or HR Manager, open Settings → HR Document Templates and show the seeded starter library alongside the type filter dropdown, then click "Duplicate" on one (e.g. Offer Letter) — show the copy appears as "Offer Letter (Copy)", already active and published, and open its Edit page to tweak the wording. As HR Officer, duplicate a different starter template (has `.create`) and confirm both Tenant Admin and HR Manager can do the same. Go to `/hr-documents/create`, pick an employee and the duplicated template, and generate — show the rendered letter uses the duplicate's *currently published* version's wording, not the original's. Click "Download PDF" on the generated document's detail page (Checkpoint 35) — as HR Officer (a draft), notice the plain-text "DRAFT — NOT YET SUBMITTED FOR APPROVAL" banner at the top of the PDF (Checkpoint 37). As HR Officer, click "Submit for approval" — status badge changes to "pending approval". Switch to HR Manager and click "Approve" — badge turns "approved", title can no longer be edited, and the PDF banner disappears. Generate a second document, submit it, and this time click "Reject" with a reason (e.g. "Please fix the start date") as HR Manager — switch back to HR Officer and show the rejection reason displayed, then "Resubmit for approval". Try approving as HR Officer (no approve permission) and confirm it's blocked — HR Officer can never self-approve, by design. Switch to Employee or Line Manager and show `/hr-documents` and `/settings/hr-document-templates` are both inaccessible (403) — HR letters are an HR-administrative function, not self-service, by deliberate design this checkpoint.
- **Recruitment & Applicant Tracking** — no demo data is pre-seeded for this module (deliberately, to keep the seed set small); create it live instead. As Tenant Admin or HR Manager, open `/recruitment/jobs` → "New job opening" (e.g. "Backend Engineer", Engineering department, open location), then move its status from Draft to Open. As HR Manager, open `/recruitment/applications` → "New application", pick the job opening, and fill in an applicant's name/email/cover letter — show it's created directly at the "applied" stage. As HR Officer, add an internal note to the application (visible only to internal HR users, never a candidate-facing view) and move it through the pipeline: Screening → Interview → Offer. Show the "Ready for conversion" toggle and the permanently-disabled "Convert to Employee (coming soon)" button next to it — no employee record is ever created automatically this checkpoint, by deliberate design. Switch to Line Manager or Employee and show `/recruitment` is inaccessible (403) — no recruitment permissions are granted to either role by default. Confirm Auditor can view job openings/applications but every mutation attempt (stage change, note, status update) is blocked.
- **Users & Access** — as Tenant Admin, show the Users list/roles list; demonstrate that removing the tenant's last Tenant Admin role is blocked.
- **Audit Log review** — as Auditor, open the audit log, show the role-assignment entries created during seeding and any entries generated live during the demo (e.g. the leave approval above) — then attempt (and get blocked from) a tenant settings write.

## 5. What Each Role Should Be Able to See

- **Tenant Admin** — everything within the tenant: all modules, all Settings sections, full Users & Access.
- **HR Manager** — employees, leave (tenant-wide), documents, policies, Onboarding/Offboarding (full manage, tenant-wide), HR Document Templates & generated documents (full manage), Recruitment (full manage — job openings and applications, including delete/mark-ready-for-conversion), Settings (Document Categories/Leave Types/Departments/Positions/Locations/HR Document Templates, full CRUD), but not Users & Access or Audit Log.
- **HR Officer** — leave (tenant-wide) and policies, Onboarding/Offboarding (create/update/assign/complete, no delete), HR document templates (view only) and generated documents (view/create/generate/update/submit, no delete, and — deliberately — no approve/reject), Recruitment (view/create/update/manage applications/add notes/mark ready for conversion, no delete), Settings visible (for Leave Types/Departments/Positions/Locations — view/create/update, no delete), but not Users & Access, Document Categories management, or Audit Log.
- **Line Manager** — their own profile plus direct reports only; leave approval limited to direct reports; sees and can complete Onboarding/Offboarding tasks only for direct reports or assigned to them; no Settings access; no HR document access (deliberately none by default); no Recruitment access (deliberately none by default — no assigned-interviewer scoping model exists yet).
- **Employee** — their own profile, own leave, own documents, own policy acknowledgements, and only Onboarding/Offboarding tasks assigned to them personally. No Settings, no visibility into other employees' data, no HR document access (deliberately none by default), no Recruitment access (deliberately none by default).
- **Auditor** — tenant-wide read access to leave/employees/Onboarding/Offboarding (view-only) and the Audit Log, plus view-only access to HR document templates/generated documents and Recruitment (job openings/applications); Settings visible (for Security & Audit) but no admin write actions anywhere.
- **Platform Super Admin** — a safe, empty-of-tenant-data dashboard; blocked (403) from every tenant-scoped `/api/v1` endpoint, including its own tenant's settings.

None of the above is enforced by hiding navigation links — every rule is
re-checked server-side on every request. See `docs/security.md` for why
the frontend is never the security boundary in this app.

## 6. Known Limitations

- No invitation flow, password reset UI, MFA, or SSO — demo users are pre-seeded, not self-registered.
- No RBAC role/permission *editing* UI yet — roles/permissions are viewable, not editable, from the UI.
- No payroll, performance, notifications, exports, or analytics charts. Onboarding/Offboarding (Checkpoint 33) exists as a foundation — no task templates, approval routing, or notifications yet.
- HR Documents & Letter Generation (Checkpoint 34) has no DOCX file, e-signature, automated sending, bulk generation, or employee self-service download. PDF download was added in Checkpoint 35 — generated on demand, never stored on disk, with a plain-text "not yet approved" banner on any non-approved document (Checkpoint 37). Template version history was added in Checkpoint 36 — no diff/compare view between versions. A single-approver approval workflow was added in Checkpoint 37 — no multi-level approval routing and no notifications when a document changes state. 8 starter templates and a Duplicate action were added in Checkpoint 38 — tenant-specific only (seeded for `uesl` only, not a global/shared library, and not automatically seeded for new tenants), no AI generation.
- Recruitment & Applicant Tracking (Checkpoint 39) is a foundation only — job openings, applicants/applications, a pipeline stage, and internal notes are real, but there's no candidate-to-employee conversion (a "ready for conversion" flag exists, but no employee record is ever created automatically), no public candidate portal, no CV parsing/AI screening, no interview scheduling, no offer approval/automation, and no bulk import. No demo data is pre-seeded for this module — create job openings/applications live during a demo.
- No platform-level dashboard for the Platform Super Admin (deliberately kept minimal/safe instead).
- Leave balances have no accrual engine or carry-forward automation — the seeded balances are a fixed, consistent snapshot, not a running calculation.
- Documents use safe fake files on the private `local` disk — there is nothing to actually "view" as a real PDF; the demo shows metadata (title, category, sensitivity, expiry), not real document contents.
- See `docs/security.md` → "Known Limitations / Follow-up" for the complete, checkpoint-by-checkpoint list.

## 7. What Not to Demo Yet

Don't attempt to show, or promise as working: full RBAC permission editing, self-service invitation/registration, MFA/SSO, payroll, performance modules, AI features, notifications, data exports, analytics dashboards, a platform-wide admin dashboard, billing/subscription management, third-party integrations, a workflow/approval-routing builder for Onboarding/Offboarding, task templates, DOCX generation or e-signature for HR Documents, multi-level/routing approval for HR Documents, bulk HR letter generation, a global/shared HR document template library across tenants, template import/export, template ratings, a public candidate portal, job-board posting, CV parsing/AI screening, interview scheduling, offer approval/automation, or candidate-to-employee conversion. PDF download (Checkpoint 35), the single-approver approval workflow (Checkpoint 37), the seeded starter templates + Duplicate action (Checkpoint 38) for HR Documents, and the Recruitment & Applicant Tracking foundation — job openings, applications, pipeline stages, internal notes, and the "ready for conversion" milestone flag (Checkpoint 39) *are* real. If asked about any of the others, the honest answer is "not built yet — see the roadmap in `docs/architecture.md`."
