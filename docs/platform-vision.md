# PeopleOS Platform Vision

**This is a durable product/architecture reference, not a checkpoint log.**
Unlike every other file in `docs/`, this one isn't tied to a specific
checkpoint and isn't expected to be "finished" — it records the
long-term direction so that every future checkpoint's design decisions
can be checked against it. Update it when the vision itself changes,
not when a feature ships.

## Mission

> PeopleOS should help organisations manage people, workflows,
> services, assets, risks, compliance, documents, approvals,
> intelligence, and operations from one secure modular platform.

PeopleOS is not meant to be only a fixed HRMS application. It should
become a secure, configurable, multi-tenant **enterprise operating
platform**, with HRMS as the first major product area. HRMS is the
starting point, but the architecture must allow future expansion into:

- Asset Management
- Enterprise Ticketing
- Enterprise Risk Management
- Compliance
- Internal Audit
- Vendor Risk
- Policy Management
- Health and Safety
- Procurement
- Facilities
- Business Continuity
- Industry-specific modules

**The most important architectural principle**: build every major
capability once at platform level, then allow all modules to reuse it.
Do not build disconnected features inside each module if they should
be shared platform services.

## Platform kernel

PeopleOS should have a stable platform kernel that provides shared
capabilities for all modules. Built once, reused across HR, Assets,
Ticketing, Risk, Compliance, and future modules. The kernel should
eventually include:

- Multi-tenancy
- Authentication and identity
- Roles and permissions
- Field-level access control
- Tenant module activation
- Tenant branding
- Workflow engine
- Rules engine
- Approval engine
- Form builder
- Page/layout designer
- Notifications and escalations
- Tasks
- Documents and signatures
- Audit logging
- Search
- Reporting and dashboards
- APIs and webhooks
- File management
- AI permissions and governance
- Data retention
- Localisation
- Integration framework
- Configuration export/import

## Module layer

Modules should plug into the platform kernel. Every module must be
independently configurable, tenant-scoped, secure, auditable,
upgradeable, and removable without breaking the core platform or other
modules. Current and future module groups include:

- Core HR
- Leave
- Recruitment
- Onboarding and Offboarding
- Performance
- Learning
- Skills and Certifications
- Career Growth Studio
- Payroll Readiness
- Benefits
- Employee Relations
- Workforce Planning
- Asset Management
- Enterprise Ticketing
- Enterprise Risk
- Compliance
- Internal Audit
- Policy Management
- Vendor Risk
- Privacy Management
- Business Continuity
- Health and Safety
- Procurement
- Facilities
- Industry-specific modules

## Module registration contract

Every module should eventually register its own capabilities with the
platform, declaring:

- name
- version
- module_key
- tenant activation requirements
- dependencies
- permissions
- navigation entries
- settings
- database entities
- custom field targets
- workflow triggers
- workflow actions
- forms
- notifications
- reports
- dashboard widgets
- API routes
- audit events
- search providers
- AI capabilities
- integration hooks

For example, an Asset Management module should be able to register:

```text
permissions:
- assets.view
- assets.create
- assets.assign
- assets.transfer
- assets.dispose
- assets.audit

workflow triggers:
- asset.created
- asset.assigned
- asset.overdue
- asset.reported_lost
- warranty.expiring

workflow actions:
- assign_asset
- transfer_asset
- request_asset_return
- create_maintenance_ticket
- mark_asset_disposed
```

The Workflow Builder should then be able to use those triggers/actions
without hardcoding asset-specific behaviour into the HR module or the
core platform.

## Module subscription and entitlement model

PeopleOS must support modular subscriptions. Clients should be able to
subscribe only to the modules they want, instead of being forced to
buy or use the whole platform at once. Therefore, module access must
eventually be separated into three layers:

1. Entitled / subscribed
2. Enabled / disabled for tenant operations
3. User permission / role access

The future access model:

```text
tenant is subscribed or entitled to the module
+ module is enabled for the tenant
+ user has the required permission
= access allowed
```

Example:

```text
A client subscribes to Core HR, Leave and Recruitment.
Tenant Admin enables Leave and Recruitment.
HR Manager has recruitment.view permission.
Only then can the HR Manager access Recruitment.
```

**Module enablement is not the same as module entitlement.**
Checkpoint 47 builds only tenant module enable/disable, not billing or
subscription enforcement. However, the module registry and
`tenant_modules` design must not block future support for:

- `tenant_module_entitlements`
- module subscriptions
- package plans
- add-on modules
- trial modules
- expired modules
- beta modules
- per-module limits
- per-tenant package rules
- billing-ready module registry

Future package examples may include: Core HR, Recruitment, Compliance,
Talent Management, Asset Management, Enterprise Risk, Full HRMS,
Enterprise Suite, industry-specific packages. The architecture should
support a tenant subscribing to any combination of modules, e.g.:

```text
Small business:        Core HR, Leave, Documents
Technology company:    Core HR, Recruitment, Assets, Enterprise Ticketing, Performance
Airline:               Core HR, Skills and Certifications, Health and Safety, Assets, Risk, Compliance
Financial institution: Core HR, Risk, Compliance, Internal Audit, Vendor Risk
```

**Do not build billing now. Do not build subscriptions now. Do not
build entitlements now.** But make sure the module registry, stable
module keys, module metadata, audit events, and tenant module design
are future-ready for modular subscription control — see "Immediate
development direction" below for what this means concretely for
Checkpoint 47.

## Event-driven architecture

Modules should communicate through defined domain events, not tight
coupling. The Employee module should not contain hard-coded logic for
every future module — it should publish an event such as
`EmployeeTerminated`, and authorised modules subscribe/respond through
controlled, audited platform services:

```text
EmployeeTerminated
    ↓
Asset Management requests asset return
Ticketing creates access-removal tasks
Payroll prepares final processing
Learning cancels future assignments
Risk checks for unresolved responsibilities
Identity integration disables access
```

Other example events: `EmployeePromoted`, `EmployeeTransferred`,
`EmployeeOnboarded`, `EmployeeOffboarded`, `LeaveApproved`,
`DocumentExpired`, `CertificationExpiring`,
`PolicyAcknowledgementOverdue`.

## Configurable HRMS direction

For HRMS specifically, PeopleOS should eventually support an
**"HR Operations Studio"** made of: Workflow Builder, Designer,
Settings, AI Assistant, Reporting and Analytics.

### Workflow Builder

Should eventually support: triggers, conditions, actions, approvals,
sequential steps, parallel steps, delegation, escalation, reminders,
SLAs, rejection paths, rework loops, workflow versioning, test mode,
execution history.

Example workflow:

```text
Employee promoted
→ manager approval
→ department-head approval
→ People Operations approval
→ update title and level
→ generate role-change letter
→ request signature
→ notify payroll and IT
→ update compensation effective date
→ assign new career framework
```

### Designer

Should eventually allow authorised admins or implementation engineers
to configure: employee profile pages, manager workspaces, request
forms, onboarding portals, offboarding checklists, performance review
forms, career development plans, promotion nomination forms, grievance
forms, dashboards, reports, email templates, notification templates,
HR letters, employee self-service pages.

Designer components should eventually include: text fields, number
fields, date fields, dropdowns, multi-selects, employee selectors,
manager selectors, department selectors, location selectors, job
selectors, file uploads, signatures, repeating tables, calculated
fields, conditional sections, instruction panels, policy links,
approval history, comments, visibility rules, required-field rules.

### Settings

Should control tenant-level configuration such as: company
information, branding, domains, departments, divisions, locations,
legal entities, job families, levels, titles, reporting relationships,
employment types, work schedules, holiday calendars, time-off
policies, compensation currencies, custom fields, roles and
permissions, sensitive-field classifications, document categories,
notification channels, integrations, data retention, authentication,
SSO, AI permissions and safety controls, audit settings, localisation,
module activation.

## AI assistant direction

The PeopleOS AI assistant should be controlled and governed, not a
generic chatbot. It should separate answer types clearly: employee
record, company policy, job description, manager-defined expectation,
general HR guidance, AI inference.

It should provide: applied filters, data timestamp, permission scope,
source documents, policy effective dates, citations/links where
authorised, warnings where data may be incomplete, confirmation before
write actions, audit logs of AI queries/actions, sensitive-data
redaction.

**The AI assistant must never bypass permissions. It must not reveal
data the user cannot access through the normal UI/API. It must not
perform material actions without confirmation.**

### BambooHR learning

The BambooHR chatbot conversation showed that an HRMS assistant can
answer useful questions, but also showed weaknesses PeopleOS should
avoid: inconsistent data retrieval, unclear filters, mixing
employee-record facts with assumptions, weak provenance for policy
answers, unclear knowledge-base search scope, privacy concerns around
chat history, lack of clear career-growth data, lack of transparent
skills/certification intelligence.

PeopleOS should improve on this by having: a canonical data
dictionary, standardised locations/departments/job titles, synonym
handling, deterministic query execution, query-result validation,
visible applied filters, source-aware answers, role-based chat-history
access, retention controls, sensitive-field masking, AI access
auditing.

## Career Growth Studio

Should support: job architecture, job families, job functions, levels,
responsibilities, competencies, technical skills, behavioural skills,
career pathways, promotion readiness, development plans, mentorship,
training recommendations, promotion workflows.

Employees should be able to see: current level, possible next roles,
required competencies, current competency gaps, recommended training,
suggested projects, mentorship opportunities, promotion-readiness
indicators.

## Skills and Certification Intelligence

Skills and certifications should become a first-class module,
tracking: skill name, skill category, proficiency level, last
validated date, validation method, certification, issuing
organisation, issue date, expiry date, credential identifier,
evidence, role relevance, required vs optional status.

This should power: skills-gap analysis, project staffing, internal
mobility, succession planning, certification reminders, compliance
reporting, training recommendations, workforce capability maps.

## Data Quality Centre

Should detect: duplicate employees, missing managers, invalid
reporting loops, inconsistent job titles, duplicate department names,
employees assigned to inactive locations, missing employment dates,
expired documents, missing compensation currencies, orphaned workflow
tasks, invalid permission assignments, inconsistent location naming.
Important because AI and reports are only trustworthy if the
underlying HR data is clean.

## Analytics and ethical AI

PeopleOS should support organisational intelligence, but must be
careful with sensitive predictions — **avoid unsafe individual-level
"flight risk" or burnout scoring.** A safer model: team-level trends,
transparent contributing factors, confidence and limitations, human
review, no automated employment decisions, bias testing, strict access
control, tenant-controlled enablement, model audit trail. The goal is
improving organisational conditions, not surveilling employees.

## Security principles

PeopleOS must treat HR data as highly sensitive. Non-negotiable
security requirements: strict tenant isolation, field-level
permissions, separate access to compensation/health/disciplinary/
identity/document data, least-privilege role templates, append-only
audit logs, permission-change auditing, report/export auditing,
AI-query auditing, sensitive-data redaction in chat, encryption at
rest and in transit, secure file validation, session and device
controls, SSO and MFA later, configurable retention, legal hold later,
tenant-specific AI settings, no cross-tenant AI context, prevention of
prompt-based permission bypass, approval before AI performs write
actions.

## Architectural test for every future feature

Before building any feature, apply this test — the answer should be
yes wherever possible:

- Is this a shared platform capability or a module capability?
- Can another module reuse it?
- Can the module be enabled or disabled per tenant?
- Can the module be removed without breaking the platform?
- Are its permissions tenant-scoped?
- Does it emit and consume defined events?
- Can it be upgraded independently?
- Is every material action audited?
- Can administrators configure it without code where appropriate?
- Can future modules integrate without changing its internals?
- Does it preserve strict tenant isolation?
- Does it avoid putting module-specific logic inside the platform
  kernel?
- Can this module be subscribed to independently?
- Can this module be enabled or disabled separately from subscription
  entitlement?
- Does access require entitlement, enablement, and permission?
- Can this module belong to one or more future package plans?
- Can this module be trialled, expired, or upgraded without breaking
  tenant data?

## Immediate development direction

Continue Checkpoint 47 as the first foundation of this platform
approach: Tenant Module Enable/Disable, Tenant Branding Foundation,
Module Registry, Module Gate Middleware, Module Gate Route Audit, Safe
Shared Frontend Module/Branding Props.

When implementing it, keep the bigger architecture in mind:

- Module keys must be stable.
- Module enablement must support future entitlements.
- Module registry should support future module contracts.
- Module gates must be reusable.
- Module dependencies should be explicit.
- Module route audit must prevent future security drift.
- Tenant configuration should remain export/import-ready later.
- Audit events should support future configuration history.
- **Checkpoint 47 must not build billing, subscriptions, or
  entitlements**, but it must make module keys stable and module
  metadata clean enough that future subscription/entitlement
  enforcement can be added without redesigning module activation.

After Checkpoint 47, the next architectural foundations should likely
be: Custom Fields Foundation, Field-Level Visibility, Custom Forms,
Workflow Engine, Approval Engine, Notification Rules, Reporting
Engine, Dashboard Builder, Form/Page Designer, Implementation Engineer
Tools, Configuration Export/Import. **Do not build these before
Checkpoint 47 is complete and explicitly approved.**

**Checkpoint 48 — Custom Fields Foundation — completed.** Delivered
the "custom field targets" capability the Module Registration Contract
above anticipates, scoped to a single entity (`recruitment_applicant`
— the lowest-blast-radius target, not `employees`) to prove the
storage/validation/audit engine before expanding it. Applied the same
discipline as Checkpoint 47:

- `field_key`/`option_key` are stable and immutable once created —
  the same "module keys must be stable" principle applied to field
  identifiers, since forms/workflow conditions/reports/AI filters will
  eventually reference them by key.
- No billing/subscription/entitlement enforcement built — a flat
  50-fields-per-tenant-per-entity cap stands in for a future
  package-dependent limit (see "Module subscription and entitlement
  model" above), without redesigning the definition table when that
  limit becomes real.
- Storage (relational definitions + one-row-per-field values, never a
  JSONB blob) is the deliberate "build once, reuse everywhere"
  choice — a future generic workflow-condition/report/dashboard/
  AI-filter engine can query `custom_field_values` the same way
  regardless of which entity or tenant it belongs to, without this
  checkpoint's storage shape changing.
- Sensitivity classification exists now but only gates audit masking,
  not read access — explicitly documented as a future Field-Level
  Visibility foundation, not built prematurely.
- Found and fixed a real pre-existing gap in `route:audit-module-gates`
  (Checkpoint 47) while wiring this checkpoint's own routes — it had
  been silently checking `routes/web.php` pages only since it never
  accounted for `routes/api.php`'s `api/v1` prefix. See
  `docs/architecture.md`/`docs/testing.md` for the full story.

Next: `job_applications` (near-zero engine work), then
`lifecycle_processes`/`leave_requests`, `employees` last — followed by
Field-Level Visibility, then Custom Forms, per the roadmap above.

## Final product promise

> One secure platform connecting people, workflows, services, assets,
> risks, compliance, documents, approvals and organisational
> intelligence.

This is the mission. Use it when making architectural decisions from
now on — every checkpoint's design choices should be checkable against
the "Architectural test" above.
