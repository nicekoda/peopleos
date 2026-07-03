# API

## Versioning

All endpoints are under `/api/v1`.

## Authentication

**No separate token-based API guard exists yet (no Sanctum).** `/api/v1`
routes are registered through `routes/web.php` (see `routes/api.php`),
running through the same `web` middleware group as everything else —
session-based auth, CSRF, `ResolveTenant`. This means API requests need
the same authenticated session a browser would have (or, in tests,
`actingAs()`).

**This is acceptable for now** — current usage is the app's own
browser/local-development client, same-origin, same session. It is
**not** acceptable once any of the following exist:

- An external API client (not this app's own frontend).
- A mobile app.
- A third-party integration.
- Any public/published API usage.

**Before any of those**, a token-based API authentication layer (Sanctum,
or equivalent) must be added, and it must support:

- **Scoped tokens** — a token should be limited to specific abilities, not
  a blanket stand-in for a full session; mirrors the existing
  `employees.*` permission granularity, not a step backward from it.
- **Rate limiting** — per-token, not just per-IP, so one compromised or
  misbehaving integration can't exhaust the limit for everyone else.
- **Audit logging** — token issuance, use, and revocation are all
  security-relevant events; log them via the existing `AuditLogger`
  (`app/Services/Audit/AuditLogger.php`), not a separate mechanism.
- **Tenant isolation** — a token must be bound to exactly one tenant (or
  explicitly platform-level, mirroring `is_platform_admin`); it must
  never be usable to reach a different tenant's data than the one it was
  issued for. This is the same guarantee `tenant.matches` currently
  provides for session auth (see `docs/security.md`) — a token-auth layer
  needs its own equivalent check, not an assumption that Sanctum handles
  it automatically.

**Not implemented this checkpoint** — deliberately. This checkpoint is
hardening and documentation of the current session-based approach, not a
speculative build-ahead of a real external-consumer need.

## Every endpoint enforces (in order)

1. **Authentication** — `auth` middleware.
2. **Tenant match** — `tenant.matches` middleware: does the authenticated user actually belong to the tenant this subdomain resolved to? (Added in Checkpoint 7 — see `docs/security.md` for the vulnerability this closes.)
3. **Active user** — `hasPermission()` fails closed for inactive users (see `docs/security.md`).
4. **Active tenant** — same fail-closed check for tenant users.
5. **Permission** — `permission:{key}` middleware, one specific permission per route/action, not a blanket check on the whole resource.
6. **Tenant scoping** — `BelongsToTenant` global scope, active before route-model-binding resolves (see the middleware-ordering note in `docs/architecture.md`).
7. **Object-level ownership** — an explicit check in the controller beyond the global scope (defense in depth — see `docs/architecture.md`).
8. **Validation** — tenant-scoped uniqueness/FK checks in the FormRequest.
9. **Audit logging** — create/update/delete all write to `audit_logs`.

## Employees

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/employees` | `employees.view` | Paginated (Laravel default, 15/page) |
| `POST` | `/api/v1/employees` | `employees.create` | |
| `GET` | `/api/v1/employees/{employee}` | `employees.view` | 404 (not 403) if the employee belongs to another tenant |
| `PATCH` | `/api/v1/employees/{employee}` | `employees.update` | Partial update — only provided fields are validated/changed |
| `DELETE` | `/api/v1/employees/{employee}` | `employees.delete` | Soft delete only |

**`tenant_id` is never accepted as request input.** It's not a field in
either `StoreEmployeeRequest` or `UpdateEmployeeRequest`'s rules, so even
if a client sends it in the JSON body, it's silently dropped — the
controller always sets it from `app(Tenant::class)->id` (the
subdomain-resolved tenant). Same for `created_by`/`updated_by`, always
set from the authenticated user.

**`manager_employee_id` is also never accepted here, as of Checkpoint
13.** Neither `StoreEmployeeRequest` nor `UpdateEmployeeRequest` validate
it — a value in the request body is silently ignored. Manager assignment
is exclusively handled by the dedicated endpoints below.

### Response shape

`EmployeeResource` — `personal_email` and `phone` are `null` in the
response unless the requesting user also has `employees.view_sensitive`
(on top of `employees.view` already required to reach the endpoint at
all). Every other field is always present when known.

```json
{
  "data": {
    "id": "01h...",
    "employee_number": "EMP-0001",
    "first_name": "Ada",
    "middle_name": null,
    "last_name": "Lovelace",
    "preferred_name": null,
    "full_name": "Ada Lovelace",
    "work_email": "ada@example.com",
    "personal_email": null,
    "phone": null,
    "status": "active",
    "employment_type": "full_time",
    "department_id": null,
    "location_id": null,
    "position_id": null,
    "manager_employee_id": null,
    "start_date": null,
    "probation_end_date": null,
    "confirmation_date": null,
    "created_at": "2026-07-02T00:00:00+00:00",
    "updated_at": "2026-07-02T00:00:00+00:00"
  }
}
```

### Validation rules

| Field | Rules |
|---|---|
| `employee_number` | required (create) / sometimes+required (update), string, unique per tenant |
| `first_name`, `last_name` | required (create) / sometimes+required (update), string |
| `middle_name`, `preferred_name` | nullable, string |
| `work_email` | nullable, valid email, unique per tenant |
| `personal_email` | nullable, valid email |
| `phone` | nullable, string |
| `status` | nullable, valid `EmployeeStatus` enum value |
| `employment_type` | required (create) / sometimes+required (update), valid `EmploymentType` enum value |
| `department_id` / `location_id` / `position_id` | nullable, must exist **and belong to the same tenant** |
| `manager_employee_id` | nullable, must exist and belong to the same tenant; **cannot be the employee's own id** (on update) |
| `start_date`, `confirmation_date` | nullable, valid date |
| `probation_end_date` | nullable, valid date, ≥ `start_date` |

Validation errors return Laravel's standard 422 shape
(`{"message": ..., "errors": {"field": ["message"]}}`) — no stack traces,
no internal detail, regardless of `APP_DEBUG`.

## Manager Hierarchy

| Method | Path | Permission | Notes |
|---|---|---|---|
| `PATCH` | `/api/v1/employees/{employee}/manager` | `employees.update_manager` | Body: `{"manager_employee_id": "..."}` — assign or change. Rejects self-assignment, cross-tenant, soft-deleted/non-active managers, and cycles (`422`) |
| `DELETE` | `/api/v1/employees/{employee}/manager` | `employees.update_manager` | Removes the manager; `404` if the employee had none |
| `GET` | `/api/v1/employees/{employee}/direct-reports` | `employees.view_team` | One level only, not recursive |
| `GET` | `/api/v1/employees/{employee}/reporting-tree` | `employees.view_team` | Recursive, depth-capped at 5 levels — see `docs/security.md` |
| `GET` | `/api/v1/me/direct-reports` | *(none — self-service)* | Scoped to the caller's own linked employee only |

See `docs/security.md#manager-hierarchy` for the full validation chain
and the fail-closed cycle-detection design.

### Validation rules

| Field | Rules |
|---|---|
| `manager_employee_id` | required, must exist, belong to the current tenant, be `status: active`, and not be soft-deleted; must not equal the target employee's own id; must not create a circular reporting relationship |

### `/me/direct-reports` — safe response when unlinked

Unlike `/me/employee` (a single resource, `404` when unlinked),
`/me/direct-reports` is a list endpoint: a caller with no linked
employee gets an **empty list with `200`**, not an error — see
`docs/security.md` for why this differs from `/me/employee`'s posture.

### `reporting-tree` response shape

```json
{
  "data": {
    "id": "01h...",
    "employee_number": "EMP-0001",
    "full_name": "Ada Lovelace",
    "...": "...(same fields as EmployeeResource)",
    "direct_reports": [
      {
        "id": "01h...",
        "full_name": "Grace Hopper",
        "direct_reports": [],
        "reports_truncated": false
      }
    ],
    "reports_truncated": false
  }
}
```

`reports_truncated: true` on a node means that employee has direct
reports which exist but weren't fetched because the response reached
the depth cap — not that they have no reports at all.

### A status-code nuance worth knowing when testing cross-tenant access

Routes with a bound `{employee}` parameter (all of the above except
`/me/direct-reports`) return `404` for a cross-tenant *session* (an
authenticated tenant-A user hitting tenant-B's subdomain with a
tenant-A employee ID), not `403` — route-model-binding's tenant scope
resolves first. `/me/direct-reports` (no route parameter) correctly
returns `403` via `tenant.matches` for the same scenario. See
`docs/security.md` for the full explanation — this is pre-existing,
consistent behavior across every `{model}`-bound route in the app, not
specific to this module.

## Employee Documents

Nested under employees — a document always belongs to exactly one
employee in the current tenant.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/employees/{employee}/documents` | `documents.view` | Paginated; sensitive documents excluded without `documents.view_sensitive` |
| `POST` | `/api/v1/employees/{employee}/documents` | `documents.upload` | `multipart/form-data`, field name `file` |
| `GET` | `/api/v1/employees/{employee}/documents/{document}` | `documents.view` | 404 if the document belongs to another tenant, another employee, or is sensitive without `documents.view_sensitive` |
| `GET` | `/api/v1/employees/{employee}/documents/{document}/download` | `documents.download` | Streams the file; same 404 conditions as above |
| `DELETE` | `/api/v1/employees/{employee}/documents/{document}` | `documents.delete` | Soft delete only |

**`tenant_id` is never accepted as request input**, same rule as
Employees. `document_category_id` must belong to the same tenant if
provided.

### Upload validation rules

| Field | Rules |
|---|---|
| `file` | required, type in `pdf`/`doc`/`docx`/`jpg`/`jpeg`/`png` (validated by detected content, not just extension), max 10MB |
| `title` | required, string |
| `description` | nullable, string |
| `document_category_id` | nullable, must exist and belong to the same tenant |
| `issue_date` | nullable, valid date |
| `expiry_date` | nullable, valid date, ≥ `issue_date`; **required** if the chosen category has `requires_expiry_date` |

### Response shape

`EmployeeDocumentResource` never includes `storage_disk`, `storage_path`,
or `stored_filename` — only `original_filename` (display metadata) and
safe fields:

```json
{
  "data": {
    "id": "01h...",
    "employee_id": "01h...",
    "document_category_id": null,
    "title": "Passport Copy",
    "description": null,
    "original_filename": "passport.pdf",
    "mime_type": "application/pdf",
    "file_extension": "pdf",
    "file_size": 102400,
    "status": "active",
    "is_sensitive": false,
    "issue_date": null,
    "expiry_date": null,
    "uploaded_by": 3,
    "approved_by": null,
    "approved_at": null,
    "created_at": "2026-07-02T00:00:00+00:00",
    "updated_at": "2026-07-02T00:00:00+00:00"
  }
}
```

### Download

`GET .../download` streams the file directly (`Storage::download()`)
through the same permission/tenant/ownership/sensitivity check chain as
every other action — there is no separate signed-URL or direct-link
mechanism (the `local` disk doesn't support one; see `docs/security.md`).

## Document Categories

Top-level (not nested) tenant-owned resource, since a category isn't
scoped to any single employee.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/document-categories` | `document_categories.view` | Paginated |
| `POST` | `/api/v1/document-categories` | `document_categories.create` | |
| `GET` | `/api/v1/document-categories/{documentCategory}` | `document_categories.view` | 404 if the category belongs to another tenant |
| `PATCH` | `/api/v1/document-categories/{documentCategory}` | `document_categories.update` | Partial update |
| `DELETE` | `/api/v1/document-categories/{documentCategory}` | `document_categories.delete` | Soft delete only — see `docs/security.md` for why this is always safe even for categories with existing documents |

**`tenant_id` is never accepted as request input**, same rule as every
other module.

### Validation rules

| Field | Rules |
|---|---|
| `name` | required (create) / sometimes+required (update), unique per tenant |
| `slug` | auto-generated from `name` if not provided (create only — never auto-regenerated on update, to avoid silently changing a stable identifier); must be a valid slug format, unique per tenant |
| `description` | nullable, max 1000 characters |
| `applies_to` | nullable, valid `DocumentAppliesTo` enum value (defaults to `employee`) |
| `is_sensitive` / `is_required` / `requires_expiry_date` | nullable, boolean (default `false`) |
| `status` | nullable, valid `DocumentCategoryStatus` enum value (defaults to `active`) |

### Effect on document uploads

An `inactive` or soft-deleted category cannot be attached to a *new*
employee document upload — `StoreEmployeeDocumentRequest`'s
`document_category_id` validation explicitly excludes both states (fixed
in this checkpoint — see `docs/security.md` for the gap this closed).
Documents already using a category that's since been archived are
completely unaffected.

## Policies

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/policies` | `policies.view` | Paginated |
| `POST` | `/api/v1/policies` | `policies.create` | Creates the policy record only — no version/content yet |
| `GET` | `/api/v1/policies/{policy}` | `policies.view` | |
| `PATCH` | `/api/v1/policies/{policy}` | `policies.update` | Setting `status` to `archived` additionally requires `policies.archive` |
| `POST` | `/api/v1/policies/{policy}/versions` | `policies.update` | Creates a new draft version; `version_number` is auto-computed |
| `POST` | `/api/v1/policies/{policy}/publish` | `policies.publish` | Body: `{"policy_version_id": "..."}` — must be a draft version of this policy with content or an attached document |
| `POST` | `/api/v1/policies/{policy}/assign` | `policies.assign` | Body: `{"employee_ids": [...], "due_date": "..."}` — policy must already be published |
| `GET` | `/api/v1/policies/{policy}/acknowledgements` | `policies.view_acknowledgements` | Paginated list of acknowledgement records |
| `POST` | `/api/v1/policies/{policy}/acknowledge` | `policies.acknowledge` | Body: `{"employee_id": "..."}` — **`employee_id` is optional as of Checkpoint 11**, see below |

**`tenant_id` is never accepted as request input**, same rule as every
other module.

### Publishing and versioning

- A policy starts as `draft` with no versions. `POST .../versions`
  creates a draft version (content optional at this stage).
- `POST .../publish` promotes a specific draft version to `published`,
  sets `policies.current_version_id`, and moves any *previously*
  published version for the same policy to `archived` — never deleted.
- Assignment always targets the policy's **current** version.

### Acknowledgement — self-service by default, admin-recorded on request

**Updated in Checkpoint 11.** `employee_id` in the request body is now
optional:

- **Omitted** (or explicitly equal to the caller's own linked employee,
  via `GET /api/v1/me/employee`) — genuine self-acknowledgement. Requires
  only `policies.acknowledge`. Recorded as
  `acknowledgement_method: "web"`.
- **Explicitly a different employee** — treated as recording on behalf of
  someone else. Requires `policies.acknowledge` **and** `policies.assign`.
  Recorded as `acknowledgement_method: "admin_recorded"`.

A caller with no linked employee and no explicit `employee_id` gets a
`422` ("You have no linked employee record..."). See
`docs/security.md#the-acknowledgement-redesign-two-paths-one-endpoint`
for the full design and why this is safe to grant to the Employee role.

Acknowledging fails with:
- `422` if there's no employee to resolve to (see above).
- `403` if acting on behalf of another employee without `policies.assign`.
- `404` if the resolved employee has no `pending` acknowledgement for this policy.
- `409` if the policy has been republished since the employee was
  assigned (their pending row points at a superseded version — no
  auto-reassignment exists yet).

### Response shapes

```json
// GET /policies/{policy}
{
  "data": {
    "id": "01h...",
    "title": "Code of Conduct",
    "slug": "code-of-conduct",
    "code": null,
    "status": "published",
    "current_version_id": "01h...",
    "effective_date": null,
    "review_date": null,
    "created_at": "2026-07-02T00:00:00+00:00"
  }
}

// POST /policies/{policy}/assign
{
  "created": ["01h...", "01h..."],
  "skipped_duplicates": []
}
```

## User ↔ Employee Linking

| Method | Path | Permission | Notes |
|---|---|---|---|
| `POST` | `/api/v1/employees/{employee}/link-user` | `employees.link_user` | Body: `{"user_id": "..."}` — both employee and user must belong to the current tenant; neither may already be linked to someone else |
| `DELETE` | `/api/v1/employees/{employee}/unlink-user` | `employees.unlink_user` | Clears `user_id`/`linked_at`/`linked_by`; `404` if the employee had no link |

See `docs/security.md#user--employee-linking` for the full design,
including why linking is HR/admin-only rather than self-service.

### Response shapes

```json
// POST /employees/{employee}/link-user
{ "message": "User linked." }

// DELETE /employees/{employee}/unlink-user
{ "message": "User unlinked." }
```

### Validation rules

| Field | Rules |
|---|---|
| `user_id` | required, integer, must exist and belong to the current tenant, must be `is_platform_admin = false` and `status = active`, must not already be linked to a different employee |

## Me

Self-scoped endpoints — resolve entirely from the authenticated session,
no route parameter, no permission middleware required.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/v1/me/employee` | Returns the caller's own linked employee record, or `404` if unlinked |

```json
// GET /me/employee (linked)
{ "data": { "id": "01h...", "employee_number": "EMP-0001", ... } }

// GET /me/employee (unlinked)
// 404 { "message": "..." }
```

## Leave Management

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/leave-types` | `leave_types.view` | Paginated |
| `POST` | `/api/v1/leave-types` | `leave_types.create` | |
| `GET` | `/api/v1/leave-types/{leaveType}` | `leave_types.view` | 404 if the type belongs to another tenant |
| `PATCH` | `/api/v1/leave-types/{leaveType}` | `leave_types.update` | Partial update |
| `DELETE` | `/api/v1/leave-types/{leaveType}` | `leave_types.delete` | Soft delete only |
| `GET` | `/api/v1/leave-requests` | `leave.view` | Own requests only, unless the caller also holds `leave.view_all` (then tenant-wide) |
| `POST` | `/api/v1/leave-requests` | `leave.request` | Self-service only — see below |
| `GET` | `/api/v1/leave-requests/{leaveRequest}` | `leave.view` | 404 if not the caller's own request and the caller lacks `leave.view_all` |
| `PATCH` | `/api/v1/leave-requests/{leaveRequest}` | `leave.request` | Owner-only, **draft status only** — `409` otherwise |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/submit` | `leave.request` | Owner-only; `draft → pending` |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/approve` | `leave.approve` | Cannot approve own request (`403`); `pending → approved` |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/reject` | `leave.reject` | Cannot reject own request (`403`); body: `{"rejection_reason": "..."}` (required); `pending → rejected` |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/cancel` | `leave.cancel` | Owner-only, no exceptions (not even for `leave.view_all` holders — see `docs/security.md`); `draft/pending → cancelled` |

**`tenant_id` is never accepted as request input**, same rule as every
other module.

### Self-service leave requests — no `employee_id` field exists

`POST /leave-requests` has no `employee_id` field at all. The employee is
always resolved from the caller's own linked employee
(`$request->user()->employee`, see `docs/security.md#user--employee-linking`).
A caller with no linked employee gets `422`. A stray `employee_id` in
the request body is silently ignored, not honored — the request is
still created for the caller's own employee.

### `total_days` is always computed server-side

Never trusted from request input, even if present in the body —
`Carbon`-computed inclusive calendar days between `start_date` and
`end_date`. See `docs/security.md` for the "calendar days, not business
days" limitation.

### Status transitions

```
draft   → pending, cancelled
pending → approved, rejected, cancelled
approved / rejected / cancelled → (terminal)
```

Any other transition (`approved → pending`, `rejected → approved`, a
second `approve` call, etc.) returns `409`.

### Response shapes

```json
// POST /leave-requests
{
  "data": {
    "id": "01h...",
    "employee_id": "01h...",
    "leave_type_id": "01h...",
    "start_date": "2027-03-01",
    "end_date": "2027-03-03",
    "total_days": 3,
    "reason": "Family trip",
    "status": "draft",
    "submitted_at": null,
    "approved_by": null,
    "rejected_by": null,
    "cancelled_by": null,
    "created_at": "2026-07-03T00:00:00+00:00"
  }
}

// POST /leave-requests/{id}/reject (missing rejection_reason)
// 422 { "message": "...", "errors": { "rejection_reason": ["..."] } }
```

### Validation rules

| Field | Rules |
|---|---|
| `leave_type_id` | required, must exist, belong to the current tenant, and be `status: active` and not soft-deleted |
| `start_date` | required, valid date |
| `end_date` | required, valid date, ≥ `start_date` |
| `reason` | nullable, max 2000 characters |
| `rejection_reason` (reject only) | required, max 2000 characters |

## Current limitations

- No export endpoint (`employees.export` permission is seeded but unused — explicitly out of scope this checkpoint).
- No bulk actions.
- No `departments`/`locations`/`positions` CRUD endpoints — they exist only to support employee FK validation (unlike `document_categories`, which now has one).
- No self-linking / invitation-token flow — linking a user to an employee is HR/admin-only, see `docs/security.md`.
- No employee profile self-update endpoint — `/me/employee` is read-only.
- No leave balances/accrual, no manager-hierarchy-scoped approval, no notifications, no calendar integration — see `docs/security.md#leave-management`.
- `reporting-tree` is depth-capped at 5 levels — no pagination/lazy-loading beyond it, no org chart UI.
- Line Manager still cannot approve/reject leave — the hierarchy foundation exists (Checkpoint 13) but `LeaveRequestController` isn't wired to use it yet.
- No policy campaign automation, reminders, or escalations.
- No acknowledgement export/report endpoint (`policies.export_acknowledgements` seeded, unused).
- No document approval workflow endpoint — `documents.approve` permission and `approved_by`/`approved_at` columns are reserved, unused.
- Pagination uses Laravel's default page-number style; no cursor pagination or configurable page size yet.
- Session-based auth only — see "Authentication" above for the full future Sanctum/token plan.

## Future

- `employees.export` — CSV/Excel export, permission already reserved.
- Salary, bank details, medical information, disciplinary records — separate future checkpoints, each with their own sensitivity handling (per the master constitution — these are explicitly more sensitive than what this checkpoint covers).
- Leave, performance, lifecycle timeline — later HR modules building on this foundation.
- A real numbering scheme for `employee_number` (currently manually provided).
- Document approval workflow (`documents.approve`, `approved_by`/`approved_at` already reserved in the schema).
- Malware scanning on upload (currently type/size/content-detection validation only, no payload scanning).
- Cloud storage (S3 or similar) — local private disk only for now; the storage layer is already abstracted behind `storage_disk`/`storage_path` on the model, so this is a lower-effort future change than it might otherwise be.
- Onboarding documents / compliance document tracking (expiring work permits, certifications) — `document_categories.requires_expiry_date` and `employee_documents.expiry_date` already exist and are enforced at upload time; a dedicated "documents expiring soon" report/notification is future work, not built yet.
- Candidate documents (`applies_to` includes `candidate`) — no candidate/recruitment module exists yet to attach documents to.
- Self-linking / invitation-token flow for User ↔ Employee linking (currently HR/admin-only).
- Employee profile self-update — `/me/employee` is read-only; no endpoint lets an employee edit their own record.
- Manager-hierarchy-scoped leave approval — `ManagerHierarchyService::isManagerOf()` now exists (Checkpoint 13); wiring `LeaveRequestController::approve()`/`reject()` to use it, and granting Line Manager `leave.approve`/`leave.reject`, is the explicit next step (see `docs/security.md`).
- Org chart UI, manager self-service dashboard, performance/probation review usage of the manager hierarchy — `ManagerHierarchyService` is built to be reusable for these, none exist yet.
- Leave balances / accrual engine, business-day calculation, weekend/holiday exclusion, half-day leave — `leave_types.max_days_per_year` already reserved.
- Leave notifications, email approval, and calendar integration.
- Policy campaigns (bulk-assign a policy to a whole department/location, scheduled/recurring re-acknowledgement cycles).
- Email/notification reminders and escalations for overdue acknowledgements.
- Auto-reassignment when a policy is republished (currently: stale assignments are correctly rejected at acknowledge time, but nothing proactively creates a new pending row).
- Acknowledgement compliance reports/dashboard, and the export endpoint (`policies.export_acknowledgements` already reserved).
- Frontend UI for policy authoring, publishing, and the acknowledgement experience — this checkpoint is API-only, same as every module so far.
- A general tenant-level (non-employee-scoped) document table — would resolve the `employee_document_id` schema mismatch on `policy_versions` cleanly.
