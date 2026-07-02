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

**Why:** no external API consumer exists yet to justify stateless token
auth. Introduce Sanctum (or similar) when one actually does — don't build
it speculatively ahead of a real need.

## Every endpoint enforces (in order)

1. **Authentication** — `auth` middleware.
2. **Active user** — `hasPermission()` fails closed for inactive users (see `docs/security.md`).
3. **Active tenant** — same fail-closed check for tenant users.
4. **Permission** — `permission:{key}` middleware, one specific permission per route/action, not a blanket check on the whole resource.
5. **Tenant scoping** — `BelongsToTenant` global scope, active before route-model-binding resolves (see the middleware-ordering note in `docs/architecture.md`).
6. **Object-level ownership** — an explicit check in the controller beyond the global scope (defense in depth — see `docs/architecture.md`).
7. **Validation** — tenant-scoped uniqueness/FK checks in the FormRequest.
8. **Audit logging** — create/update/delete all write to `audit_logs`.

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

## Current limitations

- No export endpoint (`employees.export` permission is seeded but unused — explicitly out of scope this checkpoint).
- No bulk actions.
- No `departments`/`locations`/`positions` CRUD endpoints — they exist only to support employee FK validation.
- Pagination uses Laravel's default page-number style; no cursor pagination or configurable page size yet.

## Future

- `employees.export` — CSV/Excel export, permission already reserved.
- Salary, bank details, medical information, disciplinary records, documents — separate future checkpoints, each with their own sensitivity handling (per the master constitution — these are explicitly more sensitive than what this checkpoint covers).
- Leave, performance, lifecycle timeline — later HR modules building on this foundation.
- A real numbering scheme for `employee_number` (currently manually provided).
