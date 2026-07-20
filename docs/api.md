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

## Dashboard

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/dashboard` | `dashboard.view` | Aggregate summary only — never a raw record listing. See `docs/security.md#dashboard-foundation` |

`dashboard.view` (Checkpoint 21) only grants reaching this endpoint —
every card in the response is independently gated by its own module
permission, checked again inside the controller. A user holding only
`dashboard.view` gets `200` with empty `cards`/`recent_items` arrays,
never an error.

### Response shape

```json
{
  "cards": [
    { "key": "total_employees", "label": "Total Employees", "value": 42, "href": "/employees", "permission": "employees.view" },
    { "key": "pending_leave", "label": "Pending Leave Requests", "value": 3, "href": "/leave", "permission": "leave.view" }
  ],
  "quick_links": [
    { "label": "Request leave", "href": "/leave/create" }
  ],
  "recent_items": [
    { "type": "leave", "label": "Leave request — pending", "href": "/leave/01h..." }
  ]
}
```

### Cards, by permission

| Card key | Requires | Scope |
|---|---|---|
| `total_employees` / `active_employees` | `employees.view` | Tenant-wide |
| `direct_reports` | `employees.view_team` + linked employee | Direct reports only |
| `pending_leave` | `leave.view` | Tenant-wide (`leave.view_all`), team (`leave.view_team`), or own only — see `docs/security.md#dashboard-foundation` for the exact label/scope table |
| `my_leave_balance` | `leave.view` + linked employee | Own only — current year, summed across leave types |
| `my_documents_expiring_soon` / `my_documents_recent` | `documents.view` + linked employee | **Own employee's documents only, never tenant-wide** — see `docs/security.md` for why; sensitive documents excluded unless `documents.view_sensitive` |
| `policies_total` | `policies.view` | Tenant-wide |
| `policies_pending_acknowledgement` | `policies.view_acknowledgements` | Tenant-wide |
| `my_policies_pending_acknowledgement` | `policies.acknowledge` + linked employee | Own only |

Platform Super Admins always get `403` from this endpoint —
`dashboard.view` is a tenant-scoped permission they can never hold. See
`docs/architecture.md#dashboard-foundation-checkpoint-21`.

## Tenant

Singleton endpoint (Checkpoint 22) — no `{tenant}` route parameter.
Both actions always operate on the caller's own resolved tenant
(`app(Tenant::class)`); there is no way to reference a different
tenant's record through this endpoint at all.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/tenant` | `tenant.view` | Returns the current tenant's profile |
| `PATCH` | `/api/v1/tenant` | `tenant.update` | Body: `{"name": "..."}` — **only** `name` is accepted; see below |

### Response shape

```json
// GET /api/v1/tenant
{
  "data": {
    "id": "01h...",
    "name": "Acme Corp",
    "subdomain": "acme",
    "status": "active",
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

### Allowlisted update — only `name`

`UpdateTenantRequest` defines a validation rule for exactly one field.
`subdomain`, `status`, `tenant_id`, `created_at`, `updated_at`,
`deleted_at`, and any billing/security/system-flag field are
structurally absent from the rules — sending any of them in the same
request body has no effect; only `name` is ever applied. A `tenant.updated`
audit log is written only when `name` actually changes, with safe
metadata only (`old_name`, `new_name`, `tenant_id`, `actor_user_id`).

Platform Super Admins always get `403` from this endpoint —
`tenant.view`/`tenant.update` are tenant-scoped permissions they can
never hold. See `docs/architecture.md#settings-foundation-checkpoint-22`.

## Users & Access

`User` and `Role` do not use `BelongsToTenant` (see
`docs/architecture.md#users--access-management-ui-checkpoint-23`) —
every endpoint below manually filters by tenant and excludes
platform-scoped records; this is the primary tenant boundary here, not
a backstop.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/users` | `users.view` | Paginated, tenant users only (never platform admins) |
| `POST` | `/api/v1/users` | `users.create` | The first user-creation route in this app (Checkpoint 43). Body: `{"name": "...", "email": "...", "send_invite": true\|false, "password": "..." (required only if send_invite is false), "password_confirmation": "..." (same), "role_id": 1, "employee_id": "01h..." (optional)}`. Creates the account, assigns the role, and (if `employee_id` given) links an existing, unlinked, non-terminated employee — all inside one transaction. **Checkpoint 46** — when `send_invite` is true, the account gets an unusable random password and an invite email instead; see "Invite-Email Flow for New Accounts" below. Never triggered automatically by conversion/onboarding — see "User creation" below |
| `GET` | `/api/v1/users/{user}` | `users.view` | `404` if cross-tenant or a platform admin |
| `PATCH` | `/api/v1/users/{user}` | `users.deactivate` | Body: `{"status": "active"\|"inactive"\|"suspended"}` — **only** `status` is accepted; `409` if this would leave the tenant with no active Tenant Admin |
| `GET` | `/api/v1/roles` | `roles.view` | Paginated, tenant roles only (never platform roles) |
| `GET` | `/api/v1/permissions` | `permissions.view` | Paginated, read-only catalog (tenant-scoped permission definitions only) |
| `POST` | `/api/v1/users/{user}/roles` | `users.assign_role` | Body: `{"role_id": 1}` — must be a tenant role belonging to the caller's own tenant |
| `DELETE` | `/api/v1/users/{user}/roles/{role}` | `users.assign_role` | `409` if this is the tenant's last `tenant-admin`-role holder |

### Response shapes

```json
// GET /api/v1/users/{user}
{
  "data": {
    "id": 7,
    "name": "Jane Doe",
    "email": "jane@example.com",
    "status": "active",
    "is_platform_admin": false,
    "roles": [{ "id": 3, "name": "HR Manager", "slug": "hr-manager" }],
    "linked_employee": { "id": "01h...", "full_name": "Jane Doe" },
    "last_login_at": "2026-07-01T00:00:00+00:00",
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}

// GET /api/v1/roles
{
  "data": [
    {
      "id": 3,
      "name": "HR Manager",
      "slug": "hr-manager",
      "description": null,
      "is_platform_role": false,
      "permission_count": 25,
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

Never returned: `password`, `remember_token`, `last_login_ip`,
`email_verified_at`, raw `user_role`/`role_permission` pivot rows, or a
role's actual permission list (only a computed count).

### The "last Tenant Admin" rule

Any action that would leave the tenant with zero active holders of the
seeded `tenant-admin`-slugged role is rejected with `409` — whether
that's a status change away from `active`, or removing the role
itself — regardless of who performs the action. See
`docs/security.md#users--access-management-ui` for the full design.

### Employee linking

The Users & Access UI's link/unlink actions reuse the existing
`POST`/`DELETE /employees/{employee}/link-user`/`unlink-user`
(Checkpoint 11, documented under "User ↔ Employee Linking" above)
unchanged — no new backend surface for linking exists this checkpoint.

### User creation (Checkpoint 43)

`POST /api/v1/users` is gated by `users.create` alone — not
additionally `users.assign_role` or `employees.link_user`, even though
it performs both a role assignment and (optionally) an employee link,
mirroring the single-permission-gates-a-compound-action precedent
`job_applications.convert_to_employee` already set. `role_id` is
validated exactly like `POST /users/{user}/roles` above (a tenant role,
never a platform role). `employee_id` is optional and validated exactly
like the existing employee-linking action: the target employee must
belong to the caller's own tenant, must not already be linked to a
user, and must not be terminated (`422` with a `validation.errors.employee_id`
message otherwise, not a raw exception). The response is the same
`UserResource` shape shown above — the password is never included in
it, and never written to the `user.created` audit log either.

**Deliberately never automatic.** Unlike Checkpoint 42's task-template
application (which every process-creation endpoint calls
automatically), account creation is never triggered by
`POST /job-applications/{id}/convert-to-employee` or
`.../start-onboarding` — this stays a separate, explicit action the
caller reaches on purpose. See
`docs/security.md#user-account-provisioning-checkpoint-43` for the full
design. **Checkpoint 46 added `send_invite`** — see "Invite-Email Flow
for New Accounts" below for the current request body shape.

### Password Reset

`GET`/`POST /forgot-password` and `GET`/`POST /reset-password`
(documented in the Frontend Routes table above — these are guest-only
`routes/auth.php` routes, not `/api/v1`) are this app's first real
forgot-password flow, and the first feature that sends a real email
(`Illuminate\Auth\Notifications\ResetPassword`, synchronous — no
queued job exists in this app yet). Both endpoints always return the
identical response regardless of what actually happened server-side:

- `POST /forgot-password` always redirects back with the same flashed
  `status` message, whether the email matches a real user, matches a
  user in a *different* tenant, matches a platform admin requested from
  a tenant subdomain, or doesn't exist at all. A real reset link is
  only ever generated and emailed in the one case where the email
  resolves to a user actually eligible to authenticate on the current
  domain — the identical tenant/platform-admin check `POST /login`
  already performs.
- `POST /reset-password` always throws the same single generic
  validation error (on the `email` field) for every rejection — invalid
  token, expired token, no such user, or a technically-valid token+email
  submitted from the wrong tenant's subdomain. On success, it redirects
  to `/login` with a flashed `status` message; the new password is
  hashed via the same `'password' => 'hashed'` cast every other write
  path in this app already relies on (Checkpoint 43's `POST /api/v1/users`,
  `UserSeeder`).

The emailed link itself is tenant-aware — `AppServiceProvider::boot()`
builds it against the target user's own `{subdomain}.{base_domain}`
(or the base domain for a platform admin), never the host the
`/forgot-password` request happened to arrive on. See
`docs/security.md#password-reset-checkpoint-44` for the full security
design, including why the tenant check is enforced independently at
both the send and reset steps.

### Invite-Email Flow for New Accounts

Checkpoint 46. `POST /api/v1/users`'s `send_invite` field (required,
boolean) decides how the new account gets its password:

```json
// POST /api/v1/users — send_invite: true
{
  "name": "New Hire",
  "email": "new.hire@example.com",
  "send_invite": true,
  "role_id": 4
}
```

`password`/`password_confirmation` must be omitted entirely on this
path — submitting either alongside `send_invite: true` is rejected
(`422`, error on `password`). The account is created with an unusable
random password, then an invite email
(`App\Notifications\UserInvited`) is sent pointing to the exact same
`GET /reset-password/{token}` page documented above — no new route
exists for "accepting" an invite; it's indistinguishable from a
forgot-password link once sent, only the email's subject/copy differs.

```json
// POST /api/v1/users — send_invite: false (Checkpoint 43's original shape)
{
  "name": "New Hire",
  "email": "new.hire@example.com",
  "send_invite": false,
  "password": "correct-horse-battery-staple",
  "password_confirmation": "correct-horse-battery-staple",
  "role_id": 4
}
```

Response shape is the same `UserResource` either way — never the
password, never the invite token. See
`docs/security.md#invite-email-flow-for-new-accounts-checkpoint-46` for
the full security design.

## Tenant Modules & Branding (Checkpoint 47)

Both are singleton-style tenant resources (no `{tenant}` URL
parameter, same pattern as `GET/PATCH /api/v1/tenant` — always
operates on `app(Tenant::class)`). See
`docs/architecture.md#module-registry--branding-foundation-checkpoint-47`
for the design and `docs/platform-vision.md` for the direction this
checkpoint is the first foundation of.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/tenant/modules` | `tenant.modules.view` | Every toggleable module, current `enabled` state, and a `warning_count` where available |
| `PATCH` | `/api/v1/tenant/modules/{moduleKey}` | `tenant.modules.manage` | Body: `{"enabled": true\|false}`. `422` if `moduleKey` is unknown or a core (non-toggleable) module |
| `GET` | `/api/v1/tenant/branding` | `tenant.branding.view` | Returns an empty-valued shape if the tenant has no branding row yet |
| `PATCH` | `/api/v1/tenant/branding` | `tenant.branding.manage` | Body: `{"primary_color": "#RRGGBB", "secondary_color": "#RRGGBB"}` — both optional/nullable, strict 6-digit hex only |
| `POST` | `/api/v1/tenant/branding/logo` | `tenant.branding.manage` | Multipart `logo` field — PNG/JPG/JPEG only, no SVG, max 2MB, max 2000×2000px. Replaces any existing logo |
| `DELETE` | `/api/v1/tenant/branding/logo` | `tenant.branding.manage` | Removes the current logo file and clears the field |

### Response shapes

```json
// GET /api/v1/tenant/modules
{
  "data": [
    {
      "module_key": "recruitment",
      "label": "Recruitment",
      "description": "Job openings, applications, and the hiring pipeline.",
      "enabled": true,
      "toggleable": true,
      "related_modules": ["lifecycle"],
      "warning_count": 3
    }
  ]
}

// GET /api/v1/tenant/branding
{
  "data": {
    "logo_url": "https://uesl.peopleos.test/storage/tenant-branding/01h.../aB3...xyz.png",
    "logo_original_filename": "logo.png",
    "primary_color": "#1D4ED8",
    "secondary_color": "#F59E0B"
  }
}
```

Never returned: `tenant_modules`/`tenant_branding` row IDs,
`enabled_by`/`disabled_by`/`created_by`/`updated_by`, the internal
`logo_path`, or any raw database row. A disabled module's routes
(anything under that module's own prefix — see
`TenantModule::routeGroupPrefixes()`) return:

```json
// 403 — module disabled, from any route gated by module:{key}
{
  "message": "This module is not enabled for your organisation.",
  "reason": "module_disabled"
}
```

`reason: "module_disabled"` is stable and machine-checkable — the
frontend's `toApiError()` (`resources/js/lib/api.ts`) special-cases it
to surface this exact message rather than a generic
"you don't have permission" string.

### Module gate coverage — every toggleable-module route, not just the ones above

The routes above manage *whether* a module is enabled. Separately,
every existing route that belongs to a toggleable module now carries
a `module:{key}` middleware (Recruitment → `job-openings`/
`job-applications`/`recruitment`; Lifecycle → `lifecycle-processes`/
`lifecycle-tasks`/`lifecycle`/`settings/lifecycle-task-templates`,
plus the `start-onboarding` action; Leave → `leave-types`/`leave`/
`leave-balances`, plus `me/leave-balances`; Documents →
`employees/{employee}/documents`/`document-categories`/`documents`/
`settings/document-categories`; Policies → `policies`; HR Documents →
`hr-document-templates`/`hr-generated-documents`). `php artisan
route:audit-module-gates` (mirroring the pre-existing `route:audit-
tenant-scoping`) fails CI if any route under a toggleable module's
prefix is missing this gate.

**Checkpoint 48 note**: this command's prefix matching had a real gap
— `routes/api.php` wraps every route in `Route::prefix('api/v1')`, so
an API route's registered URI is `api/v1/job-openings`, not
`job-openings`, and the matching logic never accounted for that
prefix. Since Checkpoint 47 the command had been silently checking
`routes/web.php` pages only (45 routes) — every `api/v1/*` route was
skipped, not verified. Fixed by stripping a leading `api/v1/` before
comparing; the real checked-route count is 134, still 0 missing. The
actual `module:{key}` middleware was never missing from any route —
this was a gap in the audit's own coverage, not a real access-control
hole (the live smoke test in Checkpoint 47 already proved the
middleware itself works).

## Custom Fields (Checkpoint 48, extended Checkpoint 49/51, field-level access Checkpoint 50)

Backend-owned field definitions per entity, plus their values exposed
through the owning entity's own endpoints — no standalone values API.
See `docs/architecture.md#custom-fields-foundation-checkpoint-48`,
`docs/architecture.md#custom-fields-for-job-applications-checkpoint-49`,
`docs/architecture.md#field-level-visibility-and-sensitive-field-access-checkpoint-50`,
and `docs/architecture.md#employee-custom-fields-checkpoint-51`
for the full design. Supported entities: `recruitment_applicant`
(the candidate's identity), `job_application` (the pipeline record,
`App\Models\RecruitmentApplication`), and `employee`
(`App\Models\Employee`) — the same `{entityType}` route serves all
three, just with a different value.

**No static `module:{key}` gate on these routes as of Checkpoint 51**
— which module (if any) an entity requires is resolved at runtime from
`CustomFieldEntity::requiredModule()` (`recruitment_applicant`/
`job_application` → Recruitment; `employee` → none, Employees is core)
rather than a single route-level literal, since these three routes now
serve entities that don't all belong to the same module. A disabled
required module still produces the identical `403` shape as before
(`{"message": "This module is not enabled for your organisation.", "reason": "module_disabled"}`).

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/custom-fields/{entityType}` | `custom_fields.view` | `422` if `entityType` is unknown. Returns every definition (active and inactive) for that entity, with its options/validation rules |
| `POST` | `/api/v1/custom-fields/{entityType}` | `custom_fields.manage` | Creates a definition. `field_key` immutable after creation; `422` on bad format, reserved key, duplicate key, or the 50-fields-per-entity cap |
| `PATCH` | `/api/v1/custom-fields/{customFieldDefinition}` | `custom_fields.manage` | Updates label/description/type/required/default/sensitivity/sort_order/status/options/validation_rules. `field_key` is never accepted. `field_type` change is `422` if the field already has stored values |

### Response shape

```json
// GET /api/v1/custom-fields/recruitment_applicant
{
  "data": [
    {
      "id": "01h...",
      "entity_type": "recruitment_applicant",
      "field_key": "visa_status",
      "label": "Visa Status",
      "description": null,
      "field_type": "single_select",
      "is_required": false,
      "default_value": null,
      "sensitivity": "normal",
      "sort_order": 0,
      "status": "active",
      "can_view": true,
      "can_edit": true,
      "options": [
        { "option_key": "citizen", "label": "Citizen", "sort_order": 0, "status": "active" }
      ],
      "validation_rules": []
    }
  ]
}
```

Never returned: `tenant_id`, `created_by`, `updated_by`.

**`can_view`/`can_edit` (Checkpoint 50)** are computed fresh for the
requesting user on every request, never stored — `can_view` combines
the entity's own view permission (e.g. `job_applications.view`) with
the field's sensitivity-tier permission (see below); `can_edit` does
the same with the update permission. Both are UX metadata only, to
drive frontend rendering (hide the field, or show it read-only) — the
actual security boundary is enforced server-side in
`CustomFieldValueService`, independent of these flags.

### Field-level access (Checkpoint 50)

Each field's `sensitivity` (`normal`/`sensitive`/`confidential`/
`restricted`) maps to a fixed, platform-defined permission — no
per-tenant configurable rules exist yet:

| Sensitivity | Required permission |
|---|---|
| `normal` | none — visible to anyone who can already view/edit the parent entity |
| `sensitive` | `custom_fields.access_sensitive` |
| `confidential` | `custom_fields.access_confidential` |
| `restricted` | `custom_fields.access_restricted` |

There is **no implied hierarchy** — holding `access_restricted` does
not grant `access_sensitive` or `access_confidential`; each tier is
checked independently. A value whose tier permission the requester
lacks is silently **omitted** from the `custom_field_values`/
`application_custom_field_values` read shape (same mechanism used for
a disabled field) — never a `403` on the parent read, and never a
placeholder/masked value. Writing to a field the requester lacks tier
access for returns `403` (not `422`) before any value validation
runs; there is no payload shape that bypasses this — the check is
keyed to the field's own `sensitivity`, not to how the client names
or nests the request. This enforcement lives in
`CustomFieldValueService`, not only in the API Resource or the
frontend.

Audit-log masking (Checkpoint 48) is **unchanged** by this checkpoint
— a `sensitive`/`confidential`/`restricted` value is masked in the
audit log regardless of the acting user's own tier access, since a
different, less-privileged user may read that audit log later.

### Values — exposed through the owning entity, not a separate endpoint

Both entities' values are read/written through the existing
`job-applications` endpoints, gated by the same permissions that
already control that data — no `custom_fields.*` permission is
involved in reading or writing values. **Two deliberately separate
payload keys**, never merged into one object — a field key like
`notes` can validly exist on both entities independently, and a
shared object would have no way to disambiguate which entity a
submitted key belongs to:

```json
// PATCH /api/v1/job-applications/{jobApplication} — permission: job_applications.update
{
  "custom_field_values": {
    "visa_status": "citizen",
    "skills": ["php", "react"]
  },
  "application_custom_field_values": {
    "priority_tier": "high"
  }
}
```

`custom_field_values` targets the applicant (`recruitment_applicant`);
`application_custom_field_values` targets the application itself
(`job_application`, Checkpoint 49). Either key may be omitted
independently — a request can update just one entity's fields.

```json
// GET /api/v1/job-applications/{jobApplication} — permission: job_applications.view
{
  "data": {
    "custom_field_values": {
      "priority_tier": "high"
    },
    "applicant": {
      "id": "01h...",
      "first_name": "...",
      "custom_field_values": {
        "visa_status": "citizen",
        "skills": ["php", "react"]
      }
    }
  }
}
```

The **top-level** `custom_field_values` belongs to the job
application; `applicant.custom_field_values` belongs to the
applicant — the nesting is the only thing that disambiguates them on
read, mirroring the two payload keys on write.

Only currently-active fields are ever returned or writable — a
disabled field's previously-stored value is preserved in the database
but omitted from both the read and write surface until re-enabled.
An unknown `field_key` in either payload key (including a
`recruitment_applicant` field submitted via
`application_custom_field_values`, or vice versa), a value that fails
its field's validation rules, a missing required value, or a
select/multi-select value naming an inactive or nonexistent option
key all return `422`.

**No stage/status gate on this endpoint.** `PATCH /job-applications/{id}`
does not restrict editing based on the application's `stage` or
`status` today — an application at `hired`, `rejected`, `withdrawn`,
or `archived` can still have its fields (including both custom-field
payload keys) updated. Custom field values inherit this exactly,
since they're written through the same action.

### Employee values (Checkpoint 51)

`Employee` uses a single payload key, `custom_field_values` — unlike
`job_application`, it has no nested sibling entity, so there's no
same-field-key collision risk to disambiguate with a second key:

```json
// PATCH /api/v1/employees/{employee} — permission: employees.update
{
  "custom_field_values": {
    "uniform_size": "L"
  }
}
```

```json
// GET /api/v1/employees/{employee} — permission: employees.view
{
  "data": {
    "id": "01h...",
    "first_name": "...",
    "custom_field_values": {
      "uniform_size": "L"
    }
  }
}
```

Same rules as the other two entities: only active fields are
returned/writable; a value the requester lacks tier access to is
omitted from the read and rejected with `403` on write; an unknown
`field_key` (including a `recruitment_applicant`/`job_application`
field submitted here, or an `employee` field submitted to
`job-applications`) returns `422`. `employees.view_sensitive` — the
existing gate over the system columns `personal_email`/`phone` — has
no relationship to `custom_field_values` at all; a sensitive/
confidential/restricted Employee custom field is governed solely by
`custom_fields.access_sensitive`/`access_confidential`/`access_restricted`.

## Custom Forms (Checkpoint 52)

A form is metadata only — it groups existing custom fields into
sections for display/submission, and never introduces a second way to
read or write a value. See
`docs/architecture.md#custom-forms-foundation-checkpoint-52` for the
full design. Employee (`employee`) is the only supported entity in
this checkpoint. No static `module:{key}` gate on any of these routes
— same runtime `CustomFieldEntity::requiredModule()` check as
Custom Fields above.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/custom-forms/{entityType}` | `custom_forms.view` | `422` if `entityType` is unknown. Returns every form (active and inactive) for that entity, each with its full `sections.fields` tree |
| `POST` | `/api/v1/custom-forms/{entityType}` | `custom_forms.manage` | Creates a form. `form_key` immutable after creation |
| `PATCH` | `/api/v1/custom-forms/{customForm}` | `custom_forms.manage` | Updates name/description/status/sort_order. `form_key`/`entity_type` never accepted |
| `POST` | `/api/v1/custom-forms/{customForm}/sections` | `custom_forms.manage` | Adds a section. `section_key` immutable after creation |
| `PATCH` | `/api/v1/custom-form-sections/{customFormSection}` | `custom_forms.manage` | Updates title/description/status/sort_order. `section_key` never accepted |
| `POST` | `/api/v1/custom-form-sections/{customFormSection}/fields` | `custom_forms.manage` | Adds an existing custom field to the section via `custom_field_definition_id`. `422` if the field belongs to a different tenant or a different `entity_type` than the form |
| `PATCH` | `/api/v1/custom-form-fields/{customFormField}` | `custom_forms.manage` | Updates label_override/help_text/placeholder/is_required_override/status/sort_order. `custom_field_definition_id` never accepted — remove and re-add to point at a different field |

No `custom_forms.submit` permission exists, and no separate
`GET /custom-forms/{customForm}` "show one form" route exists either
— `index()` already returns each form's full nested structure, and a
second GET route sharing the same single-segment URI shape as
`{entityType}` would never be reachable in Laravel's route matching
anyway.

### Response shape

```json
// GET /api/v1/custom-forms/employee
{
  "data": [
    {
      "id": "01h...",
      "entity_type": "employee",
      "form_key": "employee_additional_info",
      "name": "Employee Additional Information",
      "description": null,
      "status": "active",
      "sort_order": 0,
      "sections": [
        {
          "id": "01h...",
          "section_key": "general",
          "title": "General",
          "description": null,
          "sort_order": 0,
          "status": "active",
          "fields": [
            {
              "id": "01h...",
              "label_override": null,
              "help_text": "Pick a size",
              "placeholder": null,
              "is_required_override": null,
              "sort_order": 0,
              "status": "active",
              "custom_field_definition": {
                "id": "01h...",
                "entity_type": "employee",
                "field_key": "uniform_size",
                "label": "Uniform Size",
                "field_type": "text",
                "sensitivity": "normal",
                "can_view": true,
                "can_edit": true
              }
            }
          ]
        }
      ]
    }
  ]
}
```

**A field is omitted from `fields` entirely** (never returned with a
`can_view: false` flag, never a placeholder) if the underlying custom
field is disabled, or the requesting user's `can_view` for it is
false — server-enforced in `CustomFormSectionResource`, the same "omit
means omit" rule already used for values. **Forms and sections are
returned regardless of `status`** — Settings needs to see and manage
disabled ones; a live-rendering consumer (the Employee Show page)
filters to `status: "active"` client-side, the same split
responsibility `CustomFieldsCard`'s frontend already has for
definitions.

### Submitting values — no new endpoint

A form has no submit endpoint of its own. Values are still submitted
through the entity's own existing endpoint:

```json
// PATCH /api/v1/employees/{employee} — permission: employees.update
{
  "custom_field_values": {
    "uniform_size": "L"
  }
}
```

This is the identical `custom_field_values` payload documented under
Employee values above — a form's frontend simply scopes which keys it
submits to the fields in its own sections. Every existing rule still
applies unchanged: an unknown key, a tier the actor lacks, or a
disabled field all return the same `422`/`403` they always did,
completely independent of whether a form happens to reference that
field.

## Audit Logs

Read-only (Checkpoint 24) — no create/update/delete route exists
anywhere for this resource; `AuditLog` is also structurally append-only
at the model layer (`save()` on an existing row and `delete()` both
throw). `AuditLog` does not use `BelongsToTenant` (see
`docs/architecture.md#audit-log-viewing-ui-checkpoint-24`) — every
query below manually filters by tenant; this is the primary tenant
boundary, not a backstop.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/audit-logs` | `audit.view` | Paginated, tenant-scoped only (platform-level entries with `tenant_id: null` are never included); ordered `created_at desc` |
| `GET` | `/api/v1/audit-logs/{auditLog}` | `audit.view` | `404` if cross-tenant or a platform-level entry |

### Filters (all optional, all tenant-scoped)

| Filter | Validation |
|---|---|
| `module` | string |
| `action` | string |
| `severity` | one of `info`, `warning`, `critical` |
| `actor_user_id` | integer |
| `target_user_id` | integer |
| `date_from` | valid date |
| `date_to` | valid date, `after_or_equal:date_from` |

### Response shape

```json
{
  "data": {
    "id": 42,
    "tenant_id": "01h...",
    "actor_user_id": 7,
    "actor_type": "user",
    "action": "role.assigned",
    "module": "rbac",
    "auditable_type": "App\\Models\\Role",
    "auditable_id": "3",
    "target_user_id": 12,
    "description": "Role 'HR Manager' assigned to user #12.",
    "severity": "info",
    "created_at": "2026-07-01T00:00:00+00:00",
    "metadata": { "role_id": 3, "role_slug": "hr-manager" },
    "old_values": null,
    "new_values": { "role_id": 3, "role_slug": "hr-manager" }
  }
}
```

Never returned: `ip_address`, `user_agent`. `metadata`/`old_values`/
`new_values` are passed through `AuditValueSanitizer` before leaving
the Resource — any key matching a sensitive pattern (`password`,
`token`, `secret`, `key`, `bank`, `salary`, `medical`, `reason`,
`storage_path`, and more — see `docs/security.md#audit-log-viewing-ui`
for the full list) is replaced with `***MASKED***`, regardless of
whatever masking already happened at write time in `AuditLogger`.

Platform Super Admins always get `403` from both endpoints —
`audit.view` is a tenant-scoped permission they can never hold. See
`docs/architecture.md#audit-log-viewing-ui-checkpoint-24`.

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
    "department": null,
    "location": null,
    "position": null,
    "manager_employee_id": null,
    "linked_user": null,
    "start_date": null,
    "probation_end_date": null,
    "confirmation_date": null,
    "created_at": "2026-07-02T00:00:00+00:00",
    "updated_at": "2026-07-02T00:00:00+00:00",
    "custom_field_values": {}
  }
}
```

**`custom_field_values` (Checkpoint 51)** — see [Custom Fields](#custom-fields-checkpoint-48-extended-checkpoint-4951-field-level-access-checkpoint-50)
above for the full read/write shape, permission model, and how this is
entirely separate from `employees.view_sensitive`.

**`linked_user` — added Checkpoint 43.** `null` unless a `User` account
is already linked, otherwise `{"id": 7, "name": "Jane Doe"}` — mirrors
`UserResource`'s own `linked_employee` shape in reverse (id + a safe
display name only, never email/status/roles). Drives the Employee
detail page's "create/view user account" affordance — see `docs/api.md#user-creation-checkpoint-43`.

**`department`/`location`/`position` (Checkpoint 32)** each resolve to
`{"id": "...", "name": "..."}` when the corresponding `*_id` field is
set, or `null` when unassigned — resolved server-side via eager loading,
never a separate request. The raw `department_id`/`location_id`/
`position_id` fields are unchanged, kept for backward compatibility.

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
| `department_id` / `location_id` / `position_id` | nullable, must exist, belong to the same tenant, **and be `active` and not soft-deleted (Checkpoint 32)** — an archived or deleted lookup row is rejected with a 422, the same pattern Checkpoint 9 established for `document_category_id` |
| `manager_employee_id` | nullable, must exist and belong to the same tenant; **cannot be the employee's own id** (on update) |
| `start_date`, `confirmation_date` | nullable, valid date |
| `probation_end_date` | nullable, valid date, ≥ `start_date` |

Validation errors return Laravel's standard 422 shape
(`{"message": ..., "errors": {"field": ["message"]}}`) — no stack traces,
no internal detail, regardless of `APP_DEBUG`.

## Departments, Positions, Locations

Three top-level (not nested) tenant-owned lookup resources, added in
Checkpoint 32 — identical route/permission shape, so shown together.
Replace `departments` with `positions` or `locations` for the other
two; permission keys follow the same `departments.*`/`positions.*`/
`locations.*` pattern.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/departments` | `departments.view` | Paginated |
| `POST` | `/api/v1/departments` | `departments.create` | |
| `GET` | `/api/v1/departments/{department}` | `departments.view` | 404 if the department belongs to another tenant |
| `PATCH` | `/api/v1/departments/{department}` | `departments.update` | Partial update |
| `DELETE` | `/api/v1/departments/{department}` | `departments.delete` | Soft delete only |

**`tenant_id` is never accepted as request input**, same rule as every
other module.

**`slug` is never accepted as request input, at create or update.** It
is always derived server-side from `name` (via `Str::slug()`, with a
numeric disambiguation suffix if the tenant already has a matching
slug, including soft-deleted rows) — a `slug` value in the request body
is silently ignored.

### Response shape

```json
{
  "data": {
    "id": "01h...",
    "name": "Engineering",
    "slug": "engineering",
    "description": null,
    "status": "active",
    "created_at": "2026-07-05T00:00:00+00:00",
    "updated_at": "2026-07-05T00:00:00+00:00"
  }
}
```

### Validation rules

| Field | Rules |
|---|---|
| `name` | required (create) / sometimes+required (update), unique per tenant |
| `description` | nullable, max 1000 characters |
| `status` | update only; nullable, valid `Department`/`Position`/`LocationStatus` enum value (`active` or `inactive`) |

### Permission grants

| Role | view | create | update | delete |
|---|---|---|---|---|
| Tenant Admin | ✓ (wildcard) | ✓ | ✓ | ✓ |
| HR Manager | ✓ | ✓ | ✓ | ✓ |
| HR Officer | ✓ | ✓ | ✓ | — |
| Line Manager | ✓ | — | — | — |
| Auditor | ✓ | — | — | — |
| Employee | — | — | — | — |

An Employee sees their own department/position/location only as
resolved names on their own linked employee record — see "Employees"
above.

## Leave Balances

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/leave-balances` | `leave_balances.view_all` | Tenant-wide list — there's no "own balance" concept on this admin endpoint, self-service is via `/me/leave-balances` |
| `POST` | `/api/v1/leave-balances` | `leave_balances.create` | Rejects duplicate (employee, leave type, year) with `422` |
| `GET` | `/api/v1/leave-balances/{leaveBalance}` | `leave_balances.view` | 404 if the balance belongs to another tenant |
| `PATCH` | `/api/v1/leave-balances/{leaveBalance}` | `leave_balances.update` (+ `leave_balances.adjust` if `adjustment_days` is present) | Only `entitlement_days`/`carried_forward_days`/`adjustment_days` are ever accepted; rejects a change that would make `available_days` negative (`422`) |
| `GET` | `/api/v1/me/leave-balances` | *(none — self-service)* | Scoped to the caller's own linked employee only |

**`tenant_id`/`used_days`/`pending_days`/`employee_id`/`leave_type_id`/
`year` are never accepted on `PATCH`** — structurally absent from
`UpdateLeaveBalanceRequest`, only ever mutated by the leave-request
workflow (`submit`/`approve`/`reject`/`cancel`) or, for the identity
fields, fixed at creation.

### Validation rules (`POST /leave-balances`)

| Field | Rules |
|---|---|
| `employee_id` | required, must exist, belong to the current tenant, not soft-deleted |
| `leave_type_id` | required, must exist, belong to the current tenant, be `status: active`, not soft-deleted |
| `year` | required, integer, 2000–2100; must not duplicate an existing (tenant, employee, leave type, year) combination |
| `entitlement_days` | required, numeric, ≥ 0 |
| `carried_forward_days` | nullable, numeric, ≥ 0 |
| `adjustment_days` | nullable, numeric (can be negative — a correction/debit) |

### Balance effects on the existing leave request workflow

No new leave-request endpoints — `submit()`/`approve()`/`reject()`/
`cancel()` (Checkpoint 12) now check/mutate balance for
balance-controlled leave types (`leave_types.max_days_per_year` set):

| Action | Balance effect |
|---|---|
| `POST .../submit` | Checks `available_days >= total_days`; reserves into `pending_days`, or `422` ("Insufficient leave balance available for the requested dates.") if not enough |
| `POST .../approve` | Moves `total_days` from `pending_days` to `used_days` |
| `POST .../reject` | Releases `total_days` from `pending_days` |
| `POST .../cancel` | Releases `total_days` from `pending_days`, **only if the request was `pending`** — cancelling a `draft` never touches balance |

A leave type with `max_days_per_year: null` is **not balance-controlled**
— none of the above applies, no balance row is ever created for it.

### Cross-year leave requests are rejected

`POST /leave-requests`/`PATCH /leave-requests/{id}` now reject (`422`)
any request where `start_date` and `end_date` fall in different
calendar years — the balance year rule uses `start_date`'s year only;
see `docs/security.md` for the full reasoning and future direction.

### Response shape

```json
{
  "data": {
    "id": "01h...",
    "employee_id": "01h...",
    "leave_type_id": "01h...",
    "year": 2027,
    "entitlement_days": 20.0,
    "used_days": 3.0,
    "pending_days": 0.0,
    "carried_forward_days": 0.0,
    "adjustment_days": 0.0,
    "available_days": 17.0,
    "created_at": "2026-07-03T00:00:00+00:00"
  }
}
```

`available_days` is always computed, never a stored/cached value — see
`docs/security.md`.

## Frontend Routes (Inertia)

Checkpoint 16 — separate from the `/api/v1` surface above (no prefix,
served through the `web` middleware group, session-based auth same as
`/login`). See `docs/architecture.md`/`docs/security.md` for the design.

| Method | Path | Middleware | Notes |
|---|---|---|---|
| `GET` | `/login` | `guest` | Renders `Auth/Login`; redirects to `/dashboard` if already authenticated |
| `POST` | `/login` | `guest` | Content-negotiated — JSON for `Accept: application/json`, redirect otherwise. See `docs/security.md` |
| `POST` | `/logout` | `auth` | Same content negotiation |
| `GET` | `/forgot-password` | `guest` | **New in Checkpoint 44** — renders `Auth/ForgotPassword` |
| `POST` | `/forgot-password` | `guest` | Body: `{"email": "..."}`. Always redirects back with the same flashed `status` message regardless of outcome — see "Password Reset" below |
| `GET` | `/reset-password/{token}` | `guest` | Renders `Auth/ResetPassword` with `token` (route param) and `email` (`?email=` query string) as props — neither is looked up or validated here |
| `POST` | `/reset-password` | `guest` | Body: `{"token": "...", "email": "...", "password": "...", "password_confirmation": "..."}`. Redirects to `/login` with a flashed `status` message on success; a single generic validation error on `email` otherwise — see "Password Reset" below |
| `GET` | `/dashboard` | `auth`, `tenant.matches` | Real UI (Checkpoint 21) — explicit active-user/active-tenant/`dashboard.view` checks in the controller (no blanket `permission:` middleware, since Platform Super Admin must still reach this page safely — see `docs/security.md#dashboard-foundation`); fetches summary cards client-side from `/api/v1/dashboard`, skipped entirely for platform admins |
| `GET` | `/employees` | `auth`, `tenant.matches`, `permission:employees.view` | Real UI (Checkpoint 17) — list, fetched client-side from `/api/v1/employees` |
| `GET` | `/employees/create` | `auth`, `tenant.matches`, `permission:employees.create` | Create form |
| `GET` | `/employees/{employee}` | `auth`, `tenant.matches`, `permission:employees.view` | Detail — passes only `employeeId` as a prop, never employee data (see `docs/architecture.md`) |
| `GET` | `/employees/{employee}/edit` | `auth`, `tenant.matches`, `permission:employees.update` | Edit form |
| `GET` | `/leave` | `auth`, `tenant.matches`, `permission:leave.view` | Real UI (Checkpoint 18) — list + inline balances, fetched client-side from `/api/v1/leave-requests`, `/api/v1/leave-types`, `/api/v1/me/leave-balances` |
| `GET` | `/leave/create` | `auth`, `tenant.matches`, `permission:leave.request` | Create form — registered before `/leave/{id}` to avoid route-param collision |
| `GET` | `/leave/{leaveRequest}` | `auth`, `tenant.matches`, `permission:leave.view` | Detail — passes only `leaveRequestId` as a prop, never leave-request data (see `docs/architecture.md`); `404` if the request belongs to another tenant |
| `GET` | `/lifecycle` | `auth`, `tenant.matches`, `permission:lifecycle.view` | Real UI (Checkpoint 33) — list, fetched client-side from `/api/v1/lifecycle-processes`; rows already scoped server-side by `LifecycleVisibilityService` |
| `GET` | `/lifecycle/create` | `auth`, `tenant.matches`, `permission:lifecycle.create` | Create form — employee/type picker |
| `GET` | `/lifecycle/{lifecycleProcess}` | `auth`, `tenant.matches`, `permission:lifecycle.view` | Detail — passes only `processId` as a prop; `404` if cross-tenant or outside the caller's visible scope |
| `GET` | `/lifecycle/{lifecycleProcess}/edit` | `auth`, `tenant.matches`, `permission:lifecycle.update` | Edit form — status transition, dates |
| `GET` | `/lifecycle/{lifecycleProcess}/tasks/create` | `auth`, `tenant.matches`, `permission:lifecycle.create` | Add-task form |
| `GET` | `/lifecycle/tasks/{lifecycleTask}/edit` | `auth`, `tenant.matches`, `permission:lifecycle.update` | Edit-task form — passes `taskId` and `processId` as props |
| `GET` | `/employees/{employee}/documents` | `auth`, `tenant.matches`, `permission:documents.view` | Real UI (Checkpoint 19) — list, fetched client-side from `/api/v1/employees/{employee}/documents` |
| `GET` | `/employees/{employee}/documents/upload` | `auth`, `tenant.matches`, `permission:documents.upload` | Upload form — registered before `/employees/{employee}/documents/{document}` to avoid route-param collision |
| `GET` | `/employees/{employee}/documents/{document}` | `auth`, `tenant.matches`, `permission:documents.view` | Detail — passes only `employeeId`/`documentId` as props, never document data (see `docs/architecture.md`); `404` if the employee belongs to another tenant, or the document doesn't belong to this employee |
| `GET` | `/documents` | `auth`, `tenant.matches`, `permission:documents.view` | Placeholder — a tenant-wide document centre, distinct from the employee-scoped UI above; not built yet (see `docs/architecture.md`) |
| `GET` | `/policies` | `auth`, `tenant.matches`, `permission:policies.view` | Real UI (Checkpoint 20) — list, fetched client-side from `/api/v1/policies` |
| `GET` | `/policies/create` | `auth`, `tenant.matches`, `permission:policies.create` | Create form — registered before `/policies/{policy}` to avoid route-param collision |
| `GET` | `/policies/{policy}` | `auth`, `tenant.matches`, `permission:policies.view` | Detail — passes only `policyId` as a prop, never policy data (see `docs/architecture.md`); `404` if the policy belongs to another tenant |
| `GET` | `/policies/{policy}/edit` | `auth`, `tenant.matches`, `permission:policies.update` | Edit form |
| `GET` | `/policies/{policy}/versions/create` | `auth`, `tenant.matches`, `permission:policies.update` | Version create form — same permission as `POST .../versions` |
| `GET` | `/policies/{policy}/assign` | `auth`, `tenant.matches`, `permission:policies.assign` | Assignment form — employee multi-select from `/api/v1/employees` |
| `GET` | `/policies/{policy}/acknowledgements` | `auth`, `tenant.matches`, `permission:policies.view_acknowledgements` | Acknowledgement records list |
| `GET` | `/hr-documents` | `auth`, `tenant.matches`, `permission:hr_generated_documents.view` | Real UI (Checkpoint 34) — list, fetched client-side from `/api/v1/hr-generated-documents`; optional `?employeeId=` query filter (from an Employee detail page link) |
| `GET` | `/hr-documents/create` | `auth`, `tenant.matches`, `permission:hr_generated_documents.generate` | Generate form — employee/template picker; registered before `/hr-documents/{id}` to avoid route-param collision |
| `GET` | `/hr-documents/{hrGeneratedDocument}` | `auth`, `tenant.matches`, `permission:hr_generated_documents.view` | Detail — passes only `hrGeneratedDocumentId` as a prop, never document data (see `docs/architecture.md`); `404` if cross-tenant |
| `GET` | `/recruitment` | `auth`, `tenant.matches` | **New in Checkpoint 39** — landing page, no blanket permission (same "access, not data" two-layer design as `/settings`); each card is separately gated by `job_openings.view`/`job_applications.view` |
| `GET` | `/recruitment/jobs` | `auth`, `tenant.matches`, `permission:job_openings.view` | List, fetched client-side from `/api/v1/job-openings` |
| `GET` | `/recruitment/jobs/create` | `auth`, `tenant.matches`, `permission:job_openings.create` | Create form; registered before `/recruitment/jobs/{id}/edit` to avoid route-param collision |
| `GET` | `/recruitment/jobs/{jobOpening}/edit` | `auth`, `tenant.matches`, `permission:job_openings.update` | Edit form — passes only `jobId` as a prop; `404` if cross-tenant |
| `GET` | `/recruitment/applications` | `auth`, `tenant.matches`, `permission:job_applications.view` | List, fetched client-side from `/api/v1/job-applications` |
| `GET` | `/recruitment/applications/create` | `auth`, `tenant.matches`, `permission:job_applications.create` | Create form; registered before `/recruitment/applications/{id}` to avoid route-param collision |
| `GET` | `/recruitment/applications/{jobApplication}` | `auth`, `tenant.matches`, `permission:job_applications.view` | Detail — passes only `applicationId` as a prop; `404` if cross-tenant |
| `GET` | `/settings` | `auth`, `tenant.matches` | Real UI (Checkpoint 22) — explicit `tenant.settings.view`-or-platform-admin check in the controller (no blanket `permission:` middleware, same reason as `/dashboard` — see `docs/security.md#settings-foundation`); each section card independently permission-gated |
| `GET` | `/settings/company` | `auth`, `tenant.matches`, `permission:tenant.view` | Real UI — view/edit, fetched client-side from `/api/v1/tenant` |
| `GET` | `/settings/access` | `auth`, `tenant.matches`, `permission:users.view` | Real hub UI (Checkpoint 23) — cards linking to Users and Roles pages |
| `GET` | `/settings/access/users` | `auth`, `tenant.matches`, `permission:users.view` | Real UI — list, fetched client-side from `/api/v1/users` |
| `GET` | `/settings/access/users/create` | `auth`, `tenant.matches`, `permission:users.create` | **New in Checkpoint 43** — create form (role picker from `/api/v1/roles`, optional employee picker from `/api/v1/employees`); registered before `/settings/access/users/{user}` to avoid route-param collision; accepts an optional `?employeeId=` query param (from an Employee detail page's "Create user account" link) that only pre-selects the employee dropdown |
| `GET` | `/settings/access/users/{user}` | `auth`, `tenant.matches`, `permission:users.view` | Detail — passes only `userId` as a prop, never user data; `404` if the user belongs to another tenant or is a platform admin |
| `GET` | `/settings/access/roles` | `auth`, `tenant.matches`, `permission:roles.view` | Real UI — read-only list, fetched client-side from `/api/v1/roles` |
| `GET` | `/settings/document-categories` | `auth`, `tenant.matches`, `permission:document_categories.view` | Real UI (Checkpoint 25) — list, fetched client-side from `/api/v1/document-categories` |
| `GET` | `/settings/document-categories/create` | `auth`, `tenant.matches`, `permission:document_categories.create` | Create form |
| `GET` | `/settings/document-categories/{documentCategory}/edit` | `auth`, `tenant.matches`, `permission:document_categories.update` | Edit form — passes only `documentCategoryId` as a prop; `404` if cross-tenant |
| `GET` | `/settings/leave-types` | `auth`, `tenant.matches`, `permission:leave_types.view` | Real UI (Checkpoint 25) — list, fetched client-side from `/api/v1/leave-types` |
| `GET` | `/settings/leave-types/create` | `auth`, `tenant.matches`, `permission:leave_types.create` | Create form |
| `GET` | `/settings/leave-types/{leaveType}/edit` | `auth`, `tenant.matches`, `permission:leave_types.update` | Edit form — passes only `leaveTypeId` as a prop; `404` if cross-tenant |
| `GET` | `/settings/departments` | `auth`, `tenant.matches`, `permission:departments.view` | Real UI (Checkpoint 32) — list, fetched client-side from `/api/v1/departments` |
| `GET` | `/settings/departments/create` | `auth`, `tenant.matches`, `permission:departments.create` | Create form |
| `GET` | `/settings/departments/{department}/edit` | `auth`, `tenant.matches`, `permission:departments.update` | Edit form — passes only `departmentId` as a prop; `404` if cross-tenant |
| `GET` | `/settings/positions` | `auth`, `tenant.matches`, `permission:positions.view` | Real UI (Checkpoint 32) — list, fetched client-side from `/api/v1/positions` |
| `GET` | `/settings/positions/create` | `auth`, `tenant.matches`, `permission:positions.create` | Create form |
| `GET` | `/settings/positions/{position}/edit` | `auth`, `tenant.matches`, `permission:positions.update` | Edit form — passes only `positionId` as a prop; `404` if cross-tenant |
| `GET` | `/settings/locations` | `auth`, `tenant.matches`, `permission:locations.view` | Real UI (Checkpoint 32) — list, fetched client-side from `/api/v1/locations` |
| `GET` | `/settings/locations/create` | `auth`, `tenant.matches`, `permission:locations.create` | Create form |
| `GET` | `/settings/locations/{location}/edit` | `auth`, `tenant.matches`, `permission:locations.update` | Edit form — passes only `locationId` as a prop; `404` if cross-tenant |
| `GET` | `/settings/hr-document-templates` | `auth`, `tenant.matches`, `permission:hr_document_templates.view` | Real UI (Checkpoint 34) — list, fetched client-side from `/api/v1/hr-document-templates` |
| `GET` | `/settings/hr-document-templates/create` | `auth`, `tenant.matches`, `permission:hr_document_templates.create` | Create form |
| `GET` | `/settings/hr-document-templates/{hrDocumentTemplate}/edit` | `auth`, `tenant.matches`, `permission:hr_document_templates.update` | Edit form (metadata only, Checkpoint 36) — passes only `hrDocumentTemplateId` as a prop; `404` if cross-tenant. Embeds the Versions card (list/publish), fetched client-side from `/api/v1/hr-document-templates/{id}/versions` |
| `GET` | `/settings/hr-document-templates/{hrDocumentTemplate}/versions/create` | `auth`, `tenant.matches`, `permission:hr_document_templates.update` | **New in Checkpoint 36** — create-draft-version form |
| `GET` | `/settings/hr-document-template-versions/{hrDocumentTemplateVersion}/edit` | `auth`, `tenant.matches`, `permission:hr_document_templates.update` | **New in Checkpoint 36** — edit-draft-version form; passes only `hrDocumentTemplateVersionId` as a prop; loads read-only (disabled form) if the version isn't `draft`; `404` if cross-tenant |
| `GET` | `/settings/security` | `auth`, `tenant.matches`, `permission:audit.view` | Real hub UI (Checkpoint 24) — links to Audit Logs |
| `GET` | `/settings/security/audit-logs` | `auth`, `tenant.matches`, `permission:audit.view` | Real UI — list with filters, fetched client-side from `/api/v1/audit-logs` |
| `GET` | `/settings/security/audit-logs/{auditLog}` | `auth`, `tenant.matches`, `permission:audit.view` | Detail — passes only `auditLogId` as a prop, never audit data; `404` if cross-tenant |
| `GET` | `/settings/integrations` | `auth`, `tenant.matches`, `permission:tenant.settings.view` | Placeholder — no dedicated permission exists yet, falls back to the same umbrella check as the landing page |

**Leave Management UI (Checkpoint 18)** reuses `resources/js/lib/api.ts`
unchanged apart from a tightened `409` default message ("This request
can no longer be changed." — previously the more generic "This action
conflicts with the current state." from Checkpoint 17). See
`docs/security.md#leave-management-ui` for the full design, including
why Approve/Reject buttons cannot predict `resolveApprovalScope()`'s
manager-hierarchy result and simply let a resulting `403` surface like
any other.

**Document Repository UI (Checkpoint 19)** reuses the existing
`/api/v1/employees/{employee}/documents` and `/api/v1/document-categories`
endpoints (Checkpoints 8/9), plus a new `resources/js/lib/download.ts`
helper for authenticated blob downloads (`api` with `responseType:
'blob'`, never a raw browser navigation to the API URL). `document-categories.view`
was newly granted to HR Manager and Employee (previously Tenant-Admin-only)
so the upload form's category dropdown works for the roles that
actually upload documents — see `docs/security.md#document-repository-ui`.

**Policy Management UI (Checkpoint 20)** reuses the existing
`/api/v1/policies` endpoints (Checkpoint 10) plus the new read-only `GET
/api/v1/policies/{policy}/versions` documented above. `owner_user_id`
and `employee_document_id` are accepted by the backend but omitted from
every form — no safe `/api/v1/users` lookup or general document picker
exists yet. Acknowledgement is self-scoped only (no `employee_id` ever
sent by this UI); see `docs/security.md#policy-management-ui` for the
full design.

**Dashboard Foundation (Checkpoint 21)** replaces the Checkpoint 16
placeholder with real cards fetched from the new `GET /api/v1/dashboard`
(documented above). `dashboard.view` only grants reaching the page/endpoint;
every card is independently gated by its own module permission. Platform
Super Admins see a safe static message and never call the dashboard API
at all. See `docs/security.md#dashboard-foundation`.

**Settings Foundation (Checkpoint 22)** replaces the Checkpoint 16
placeholder with real, permission-aware section cards plus one fully
real section (Company Profile, backed by the new `GET`/`PATCH
/api/v1/tenant` documented above). `tenant.settings.view` only grants
reaching `/settings`; every section card is independently gated by its
own permission. Platform Super Admins see a safe static Settings page
and are blocked from `/api/v1/tenant` with a clean `403`. See
`docs/security.md#settings-foundation`.

**Users & Access Management UI (Checkpoint 23)** reuses the new
`/api/v1/users`/`/api/v1/roles`/`/api/v1/permissions` endpoints
documented above, plus the existing Checkpoint 11
`link-user`/`unlink-user` endpoints for employee linking. Role/status
management stays Tenant-Admin-only this checkpoint. See
`docs/security.md#users--access-management-ui`.

**Audit Log Viewing UI (Checkpoint 24)** reuses the new
`GET /api/v1/audit-logs`/`GET /api/v1/audit-logs/{auditLog}` endpoints
documented above, plus the existing `GET /api/v1/users` (Checkpoint 23)
for client-side actor/target name resolution — no new enrichment
endpoint was built. Read-only end to end. See
`docs/security.md#audit-log-viewing-ui`.

**Document Categories & Leave Types Admin UI (Checkpoint 25)** reuses
the existing `/api/v1/document-categories` (Checkpoint 9) and
`/api/v1/leave-types` (Checkpoint 12) endpoints unchanged apart from
the `created_by`/`updated_by` removal documented above — no new backend
endpoint was needed for this checkpoint at all. See
`docs/security.md#document-categories--leave-types-admin-ui`.

**HR Documents & Letter Generation Foundation UI (Checkpoint 34)**
reuses the new `/api/v1/hr-document-templates`/`/api/v1/hr-generated-documents`
endpoints documented above. `content_template`/`rendered_content` are
always rendered as plain text (a `<textarea>` on the template
Create/Edit forms, `{content}` inside a `whitespace-pre-wrap` `<div>`
on the generated-document Show page) — never `dangerouslySetInnerHTML`,
the same rule Checkpoint 20 established for Policy content. The
Employee detail page gains a permission-gated "HR Documents" link to
`/hr-documents?employeeId={id}`, the same `?employeeId=` filter
convention Checkpoint 33 established for `/lifecycle`. See
`docs/security.md#hr-documents--letter-generation-foundation-checkpoint-34`.

**HR Document Template Versioning Foundation UI (Checkpoint 36)**
reuses the new `/api/v1/hr-document-templates/{id}/versions` and
`/api/v1/hr-document-template-versions/{id}` endpoints documented
above. The template Edit page's `content_template` textarea moved into
two new pages (`VersionCreate`, `VersionEdit`) plus an inline "Versions"
card (list, status badges, Publish button, "(current)" marker) on Edit
itself — no dedicated template Show page was added, keeping this
checkpoint's new UI surface to exactly what versioning needs.
`VersionEdit` loads and displays a non-draft version read-only (form
disabled, explanatory banner) rather than 404ing on a status it can't
edit. The `/hr-documents/create` template picker now also filters to
`current_version_id !== null`, so a template that's `active` but has
never had anything published is never offered as a generation source.
See `docs/security.md#hr-document-template-versioning-foundation-checkpoint-36`.

**HR Document Approval Workflow Foundation UI (Checkpoint 37)** adds no
new pages or routes — Submit/Approve/Reject are buttons on the existing
`HrDocuments/Show.tsx`, each wrapped in its own `PermissionGate`
(`.submit`/`.approve`/`.reject`) and shown only when the document's
current status makes that action legal (`canSubmit`/`canReview`
computed client-side purely for UX — the backend's `canTransitionTo()`
is the actual enforcement). The title-edit card is replaced with a
read-only "Approval" summary (submitted/approved dates) once the
document leaves `draft`/`rejected`. Reject opens an inline reason
textarea rather than a separate page — no new UI surface beyond exactly
what the workflow needs. See
`docs/security.md#hr-document-approval-workflow-foundation-checkpoint-37`.

### Shared props (every Inertia response)

```json
{
  "auth": {
    "user": {
      "id": 7,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "is_platform_admin": false,
      "employee_id": "01h...",
      "permissions": ["employees.view", "leave.view", "..."]
    }
  },
  "tenant": { "id": "01h...", "name": "UESL" },
  "errors": {}
}
```

`auth.user` is `null` when unauthenticated (only reachable on `/login`).
`tenant` is `null` whenever no tenant is resolved (Platform Super Admin
on the base domain) — never fabricated. See `docs/security.md` for the
full "what's shared, what never is" list.

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

**`document_categories.view` is granted to HR Manager and Employee as of
Checkpoint 19** (previously Tenant-Admin-only) — the Document Repository
UI's upload form needs this list to show category names, sensitivity
indicators, and expiry-date requirements; `create`/`update`/`delete`
remain Tenant-Admin-only. See `docs/security.md#document-repository-ui`.

**`created_by`/`updated_by` were removed from the response as of
Checkpoint 25** — no consumer used them, and they had no place in the
new admin UI at `/settings/document-categories`. See
`docs/security.md#document-categories--leave-types-admin-ui`.

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
| `GET` | `/api/v1/policies/{policy}/versions` | `policies.view` | **New in Checkpoint 20** — paginated, scoped through `$policy->versions()` (never a free query by `policy_id`); added so the frontend can show current-version content and let the user pick a draft to publish — see `docs/security.md#policy-management-ui` |
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

**`created_by`/`updated_by` were removed from `LeaveTypeResource` as of
Checkpoint 25** — same reasoning as Document Categories above. A new
admin UI exists at `/settings/leave-types` (list/create/edit) — see
`docs/security.md#document-categories--leave-types-admin-ui`, including
the one deliberate exception to this app's "omit blank fields" form
convention: a blank `max_days_per_year` on the Edit form is sent as an
explicit `null`, not omitted, so a capped leave type can be turned back
into unlimited.

| `GET` | `/api/v1/leave-requests` | `leave.view` | Scope depends on what else the caller holds — see "Visibility scope" below |
| `POST` | `/api/v1/leave-requests` | `leave.request` | Self-service only — see below |
| `GET` | `/api/v1/leave-requests/{leaveRequest}` | `leave.view` | See "Visibility scope" below; `404` if out of scope |
| `PATCH` | `/api/v1/leave-requests/{leaveRequest}` | `leave.request` | Owner-only, **draft status only** — `409` otherwise |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/submit` | `leave.request` | Owner-only; `draft → pending` |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/approve` | `leave.approve` | **Checkpoint 14: manager-hierarchy-scoped** — see "Approval scope" below; cannot approve own request (`403`); `pending → approved` |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/reject` | `leave.reject` | Same scoping as approve; body: `{"rejection_reason": "..."}` (required); `pending → rejected` |
| `POST` | `/api/v1/leave-requests/{leaveRequest}/cancel` | `leave.cancel` | Owner-only, no exceptions (not even for `leave.view_all` holders — see `docs/security.md`); `draft/pending → cancelled` |

### Visibility scope (`GET /leave-requests`, `GET /leave-requests/{id}`)

| Permission held | List (`index`) | Single (`show`) |
|---|---|---|
| `leave.view_all` | Every leave request in the tenant | Any request — `200` |
| `leave.view_team` (no `view_all`) | Own + direct reports' requests only — **not** the full reporting tree | Own or a direct report's — `200`; otherwise `404` |
| `leave.view` only | Own requests only | Own only — `200`; otherwise `404` |
| No linked employee, no `leave.view_all` | Empty list, `200` | `404` |

A caller holding both `leave.view_all` and `leave.view_team` gets the
broader (`leave.view_all`) scope.

### Approval/rejection scope (`POST .../approve`, `POST .../reject`) — Checkpoint 14

Holding `leave.approve`/`leave.reject` (the route-level permission) is
**necessary but no longer sufficient**. The caller must additionally
qualify as one of:

- **`hr_admin`** — holds `leave.view_all`. Can act on any pending
  request in the tenant (except their own).
- **`direct_manager`** — has a linked employee who **directly**
  manages the request's employee (`manager_employee_id` points straight
  at the caller's own employee — indirect/skip-level reports don't
  qualify, a deliberate scope decision, see `docs/security.md`).

Neither → `403`, regardless of holding `leave.approve`/`leave.reject`.
Self-approval/self-rejection is blocked before this check even runs,
for both scopes equally.

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
| `end_date` | required, valid date, ≥ `start_date`, and **same calendar year as `start_date`** (Checkpoint 15 — cross-year requests rejected) |
| `reason` | nullable, max 2000 characters |
| `rejection_reason` (reject only) | required, max 2000 characters |

## Lifecycle Processes & Tasks

Checkpoint 33 — Onboarding & Offboarding Foundation. One generic
resource (`type` distinguishes onboarding/offboarding), gated by a
single `lifecycle.*` permission set.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/lifecycle-processes` | `lifecycle.view` | Paginated; scoped by `LifecycleVisibilityService` for non-HR/Admin/Auditor callers — see `docs/security.md` |
| `POST` | `/api/v1/lifecycle-processes` | `lifecycle.create` | **Checkpoint 42** — also applies every matching (same tenant + type) `lifecycle_task_templates` row as a real task, inside the same transaction — see "Onboarding & Offboarding Task Templates" below |
| `GET` | `/api/v1/lifecycle-processes/{lifecycleProcess}` | `lifecycle.view` | 404 if cross-tenant or outside the caller's visible scope |
| `PATCH` | `/api/v1/lifecycle-processes/{lifecycleProcess}` | `lifecycle.update` | Rejected (422) if the process is `completed`/`cancelled`, or if the requested status isn't a legal transition |
| `DELETE` | `/api/v1/lifecycle-processes/{lifecycleProcess}` | `lifecycle.delete` | Soft-cancel: transitions to `cancelled` (unless already terminal) then soft-deletes |
| `POST` | `/api/v1/lifecycle-processes/{lifecycleProcess}/tasks` | `lifecycle.create` | Also requires `lifecycle.assign_task` if `assigned_to_user_id` is provided; **Checkpoint 45** — new task is appended (`sort_order` = current max + 1), never defaulted to the front |
| `POST` | `/api/v1/lifecycle-processes/{lifecycleProcess}/tasks/reorder` | `lifecycle.update` | **Checkpoint 45** — bulk reorder; see "Lifecycle Task Ordering & Reminders" below |
| `PATCH` | `/api/v1/lifecycle-tasks/{lifecycleTask}` | `lifecycle.update` | `status` here only accepts `pending`/`in_progress` — reaching `completed`/`skipped` requires the dedicated actions below |
| `DELETE` | `/api/v1/lifecycle-tasks/{lifecycleTask}` | `lifecycle.delete` | Soft delete only |
| `POST` | `/api/v1/lifecycle-tasks/{lifecycleTask}/complete` | `lifecycle.complete_task` | Also requires `LifecycleVisibilityService::canAccessTask()` — own assignment, a direct report's process, or HR/Admin-tier |
| `POST` | `/api/v1/lifecycle-tasks/{lifecycleTask}/skip` | `lifecycle.complete_task` | Same object-level scope as complete |
| `GET` | `/api/v1/lifecycle-task-templates` | `lifecycle_task_templates.view` | Paginated, ordered by type then sort_order then title |
| `POST` | `/api/v1/lifecycle-task-templates` | `lifecycle_task_templates.create` | Body: `{"type": "onboarding"\|"offboarding", "title": "...", "description": "..." (optional), "due_in_days": 0-365 (optional), "sort_order": 0-1000 (optional, default 0)}` |
| `GET` | `/api/v1/lifecycle-task-templates/{lifecycleTaskTemplate}` | `lifecycle_task_templates.view` | |
| `PATCH` | `/api/v1/lifecycle-task-templates/{lifecycleTaskTemplate}` | `lifecycle_task_templates.update` | Same body shape, all fields `sometimes` |
| `DELETE` | `/api/v1/lifecycle-task-templates/{lifecycleTaskTemplate}` | `lifecycle_task_templates.delete` | Soft delete ("archive") — stops being applied to new processes, never affects tasks already generated |

**No standalone `GET` for a single task** — the Task Edit UI fetches
the parent process (which eager-loads `tasks`) and finds the task
client-side by ID instead.

## Onboarding & Offboarding Task Templates

Checkpoint 42. A tenant-owned catalog (`lifecycle_task_templates`) of
default tasks per `LifecycleProcess` type. `LifecycleTaskTemplateApplier`
copies every non-archived template matching a newly created process's
own tenant + type into a real `LifecycleTask` row — on both
`POST /api/v1/lifecycle-processes` and
`POST /job-applications/{id}/start-onboarding` (Checkpoint 41). Copied
fields: `title`, `description` verbatim; `due_date` computed as
`now()->addDays(due_in_days)` when the template has one, otherwise left
`null`; `status` always `pending`; `assigned_to_user_id` always `null`
(no template can know who should get the task — assign it afterward via
the existing `PATCH /api/v1/lifecycle-tasks/{id}`). See
`docs/security.md` for the full permission model and tenant-isolation
guarantees.

### Response shape

```json
// GET /api/v1/lifecycle-processes/{id}
{
  "data": {
    "id": "01h...",
    "employee_id": "01h...",
    "employee": { "id": "01h...", "full_name": "Ada Lovelace" },
    "type": "onboarding",
    "status": "in_progress",
    "started_at": "2026-07-05T00:00:00+00:00",
    "due_date": "2026-07-19",
    "completed_at": null,
    "tasks": [
      {
        "id": "01h...",
        "process_id": "01h...",
        "title": "Set up laptop",
        "description": null,
        "assigned_to_user_id": 4,
        "assigned_to": { "id": 4, "name": "IT Support" },
        "status": "pending",
        "due_date": "2026-07-08",
        "sort_order": 0,
        "completed_at": null,
        "created_at": "2026-07-05T00:00:00+00:00",
        "updated_at": "2026-07-05T00:00:00+00:00"
      }
    ],
    "created_at": "2026-07-05T00:00:00+00:00",
    "updated_at": "2026-07-05T00:00:00+00:00"
  }
}
```

`employee`/`assigned_to` resolve to `{id, name}` when set, `null`
otherwise — same pattern Checkpoint 32 established for Employee's
resolved department/position/location. The raw `employee_id`/
`assigned_to_user_id` fields are kept alongside for compatibility.
`tasks` is always returned ordered by `sort_order` then `created_at`
(Checkpoint 45) — the ordering is applied once, in
`LifecycleProcess::tasks()` itself, so every response that includes
`tasks` (this one, the reorder endpoint's response) is consistent.

### Validation rules

| Field | Rules |
|---|---|
| `employee_id` (process create only) | required, must exist and belong to the current tenant, not soft-deleted |
| `type` (process create only) | required, valid `LifecycleProcessType` (`onboarding`/`offboarding`) — immutable after create |
| `status` (process/task update) | must be a legal transition from the record's *current* status (see `LifecycleProcessStatus`/`LifecycleTaskStatus::canTransitionTo()`); task `status` here is further restricted to `pending`/`in_progress` only |
| `started_at`, `due_date` | nullable, valid date |
| `title` (task) | required (create) / sometimes+required (update), max 255 |
| `description` (task) | nullable, max 2000 |
| `assigned_to_user_id` (task) | nullable, must exist, belong to the current tenant, not a platform admin, and `status: active`; requires `lifecycle.assign_task` |
| `task_ids` (reorder only) | required array, must be exactly the process's current task ID set — no more, no fewer, no foreign IDs, no duplicates |

## Lifecycle Task Ordering & Reminders

Checkpoint 45. Two additions to the Lifecycle module above: bulk task
reordering, and the app's first scheduled task (a daily overdue/due-soon
digest email — not an HTTP endpoint at all; see below). See
`docs/architecture.md` and `docs/security.md` for the full design.

### `POST /api/v1/lifecycle-processes/{lifecycleProcess}/tasks/reorder`

Requires `lifecycle.update`. Body is the complete desired order:

```json
// POST /api/v1/lifecycle-processes/{id}/tasks/reorder
{
  "task_ids": ["01hTaskC...", "01hTaskA...", "01hTaskB..."]
}
```

Response (200) is the process's tasks, re-fetched in the new order —
same shape as the `tasks` array in `GET /api/v1/lifecycle-processes/{id}`:

```json
{
  "data": [
    { "id": "01hTaskC...", "sort_order": 0, "...": "..." },
    { "id": "01hTaskA...", "sort_order": 1, "...": "..." },
    { "id": "01hTaskB...", "sort_order": 2, "...": "..." }
  ]
}
```

Rejected (422) if `task_ids` isn't exactly the process's current task
ID set, or if the process is `completed`/`cancelled`. 404 if the
process belongs to a different tenant (same `ensureBelongsToCurrentTenant()`
pattern as every other action on this controller).

### `lifecycle:send-task-digest` — not an HTTP endpoint

Registered via `bootstrap/app.php`'s `->withSchedule()`, running daily
at 07:00 server time — not reachable via any route, permission, or
button anywhere in this app; only via `php artisan lifecycle:send-task-digest`
directly or the scheduler. For every active tenant, finds every
pending/in-progress task overdue or due within 3 days with a still-
active assignee, groups by assignee, and sends one digest email per
assignee (`App\Notifications\LifecycleTaskDigestNotification`) — never
one per task. Sent synchronously (not queued — see `docs/deployment.md`
§6). Writes one `lifecycle_task_digest.sent` audit log entry per tenant
per run (only when at least one email was actually sent).

## HR Document Templates & Generated Documents

Checkpoint 34 — HR Documents & Letter Generation Foundation.
Content-only (Option A, approved) — `rendered_content` is plain text,
no PDF/DOCX file is generated. Two split permission sets, matching the
two resources' different trust levels — see `docs/security.md`.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/hr-document-templates` | `hr_document_templates.view` | Paginated |
| `POST` | `/api/v1/hr-document-templates` | `hr_document_templates.create` | `slug` auto-generated from `title` if omitted |
| `GET` | `/api/v1/hr-document-templates/{hrDocumentTemplate}` | `hr_document_templates.view` | |
| `PATCH` | `/api/v1/hr-document-templates/{hrDocumentTemplate}` | `hr_document_templates.update` | Metadata only (Checkpoint 36) — `content_template` is not a field here; see "HR Document Template Versions" below |
| `DELETE` | `/api/v1/hr-document-templates/{hrDocumentTemplate}` | `hr_document_templates.delete` | Soft delete only ("archive") |
| `POST` | `/api/v1/hr-document-templates/{hrDocumentTemplate}/duplicate` | `hr_document_templates.create` | **New in Checkpoint 38** — copies metadata + the current published version into a brand-new template (`active`, version 1, `published`) — see "Template duplication" below |
| `GET` | `/api/v1/hr-generated-documents` | `hr_generated_documents.view` | Paginated; optional `?employee_id=` filter, validated against the current tenant (`404` if the employee belongs to another tenant) |
| `POST` | `/api/v1/hr-generated-documents` | `hr_generated_documents.generate` | Both creates and renders in one step, always as `draft` (Checkpoint 37) — see "Generation is a single action" below. Body: `{"employee_id": "...", "hr_document_template_id": "...", "title": "..." (optional)}` |
| `GET` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}` | `hr_generated_documents.view` | |
| `GET` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}/download-pdf` | `hr_generated_documents.view` | **New in Checkpoint 35**, watermarked Checkpoint 37 — renders `rendered_content` to PDF on demand via `dompdf/dompdf` and streams it; nothing is ever written to disk. A plain-text banner is added when `status` isn't `approved`. Same permission as the JSON `show` route above, not a new one. Response: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="{slug}.pdf"` |
| `PATCH` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}` | `hr_generated_documents.update` | `title` only — `status`/`rendered_content`/`generated_by`/approval fields are never accepted here. Rejected (`422`) unless `status` is `draft` or `rejected` (Checkpoint 37) |
| `POST` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}/submit` | `hr_generated_documents.submit` | **New in Checkpoint 37** — `draft`/`rejected` → `pending_approval`. Logged as `.submitted` or `.resubmitted` depending on the prior status |
| `POST` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}/approve` | `hr_generated_documents.approve` | **New in Checkpoint 37** — `pending_approval` → `approved` only. `approved_at`/`approved_by` set server-side |
| `POST` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}/reject` | `hr_generated_documents.reject` | **New in Checkpoint 37** — `pending_approval` → `rejected` only. Body: `{"rejection_reason": "..."}` (required, plain text, max 1000 chars) |
| `DELETE` | `/api/v1/hr-generated-documents/{hrGeneratedDocument}` | `hr_generated_documents.delete` | Soft delete ("archive"), reachable from any non-terminal status including `pending_approval` (Checkpoint 37 — unconditional, unchanged from pre-Checkpoint-37 behavior) |

**`hr_generated_documents.create` is seeded but not wired to any route
this checkpoint** — the only write path is `.generate`, which both
creates and renders in one request. Same "seeded ahead of use" posture
as the existing unused `audit.export` permission.

### Template duplication (Checkpoint 38)

`POST /api/v1/hr-document-templates/{hrDocumentTemplate}/duplicate`
reuses `hr_document_templates.create` rather than a new permission —
duplicating is just creating a new template pre-filled from an
existing one, so it's gated the same way as `POST
/api/v1/hr-document-templates`. Requires the source template to have a
current published version (`422` otherwise — there's nothing to
copy). Behavior:

- Copies `description`/`document_type` from the source; `title`/`slug`
  are auto-generated as `"{source title} (Copy)"`,
  `"{source title} (Copy 2)"`, etc. until unique within the tenant.
- The source's current published version's `content_template` is
  copied into a brand-new version 1, created directly as `published`
  (matching the existing single-step create-with-version-1 flow —
  there is no intermediate draft to publish).
- The new template always starts `active`, regardless of the source
  template's status.
- Scoped to the current tenant like every other route here —
  `404`/`403` on a cross-tenant template ID, and unreachable for
  platform-admin users (no tenant context).
- Logged as `hr_document_template.duplicated`, with the source
  template's ID in the audit metadata — never the copied content
  itself.
- Demo data: the `uesl` tenant is seeded with 8 starter templates
  (offer, promotion, warning, exit, reference, contractor engagement,
  employment confirmation, probation completion) via
  `DemoDataSeeder`, each already `active` with a `published` version 1
  built only from the allowlisted placeholder tokens below — they
  behave like any other tenant-created template, just pre-populated so
  a new tenant isn't starting from a blank template list.

### Generation is a single action, not a two-step draft-then-render flow

`POST /api/v1/hr-generated-documents` validates `employee_id` belongs
to the current tenant and `hr_document_template_id` belongs to the
current tenant, is `active`, *and has a published version*
(`current_version_id` non-null — Checkpoint 36) at the FormRequest
layer, re-checks both in the controller (including that the resolved
version is genuinely `published`, guarding a race), renders the
template's **current published version's** `content_template` via
`App\Services\HrDocuments\PlaceholderRenderer::render()`, and persists
the result immediately — there is no unrendered intermediate state to
wait for, `rendered_content` is complete the moment the row is created.
`status` is always `draft` (Checkpoint 37) regardless of the
generating user's permissions — submitting for approval is always a
separate, explicit next step, via `POST .../submit`. `hr_document_template_version_id`
records exactly which version was used. `document_type`/`title` are
copied from the template at generation time (a `title` override in the
request body is optional), so editing/archiving a template or
publishing a new version later never changes a document already
generated.

### Approval workflow (Checkpoint 37)

```
draft ──submit──► pending_approval ──approve──► approved ──archive──► archived
                        │                                                  ▲
                        └──reject──► rejected ──submit (resubmit)──────────┘
draft ──archive────────────────────────────────────────────────────────────►
```

Centralized in `App\Enums\HrGeneratedDocumentStatus::canTransitionTo()`
— every submit/approve/reject/archive action checks it server-side
before writing anything; an invalid transition is rejected (`422`),
never partially applied. `approved` only transitions to `archived` —
never editable (the existing `PATCH` is rejected once `status` leaves
`draft`/`rejected`), never resubmittable. Archiving is reachable from
every non-terminal status, including `pending_approval` (unconditional,
matching this endpoint's behavior before this checkpoint). `submitted_at`/`submitted_by`/`approved_at`/`approved_by`/`rejected_at`/`rejected_by`
are set server-side only, inside their respective action — never
accepted from request input, regardless of what the request body
contains. `rejection_reason` is required on reject, stored, and
returned on the resource (so the submitter can see why) but never
included in audit-log metadata.

### Placeholder allowlist

Only these exact `{{...}}` tokens are substituted in `content_template`:
`{{employee.name}}`, `{{employee.employee_number}}`, `{{employee.email}}`,
`{{employee.department}}`, `{{employee.position}}`, `{{employee.location}}`,
`{{employee.employment_type}}`, `{{employee.start_date}}`,
`{{tenant.name}}`, `{{today}}`. Any other `{{...}}` text (a typo, an
unrecognized field, an attempted injection) is left completely
unchanged in `rendered_content` — never executed, never a validation
error. See `docs/security.md#hr-documents--letter-generation-foundation-checkpoint-34`
for the full safety design and `tests/Unit/PlaceholderRendererTest.php`
for the exact cases covered.

### Response shapes

```json
// GET /hr-document-templates/{id}
{
  "data": {
    "id": "01h...",
    "title": "Employment Letter",
    "slug": "employment-letter",
    "description": null,
    "document_type": "employment_letter",
    "status": "active",
    "current_version_id": "01h...",
    "created_at": "2026-07-05T00:00:00+00:00",
    "updated_at": "2026-07-05T00:00:00+00:00"
  }
}

// POST /hr-generated-documents
{
  "data": {
    "id": "01h...",
    "employee_id": "01h...",
    "employee": { "id": "01h...", "full_name": "Jane Doe", "employee_number": "EMP-00042" },
    "hr_document_template_id": "01h...",
    "hr_document_template_version_id": "01h...",
    "employee_document_id": null,
    "title": "Employment Letter",
    "document_type": "employment_letter",
    "status": "draft",
    "rendered_content": "Dear Jane Doe, ...",
    "generated_at": "2026-07-05T00:00:00+00:00",
    "generated_by": 7,
    "submitted_at": null,
    "submitted_by": null,
    "approved_at": null,
    "approved_by": null,
    "rejected_at": null,
    "rejected_by": null,
    "rejection_reason": null,
    "created_at": "2026-07-05T00:00:00+00:00",
    "updated_at": "2026-07-05T00:00:00+00:00"
  }
}
```

### PDF export (Checkpoint 35) — generate on demand, never store

`GET /hr-generated-documents/{id}/download-pdf` calls
`App\Services\HrDocuments\HrDocumentPdfRenderer::render()`, which
builds a small, code-owned HTML document (title, employee name, tenant
name, generated date, and `rendered_content` — every value passed
through `e()` before interpolation, `rendered_content` additionally
through `nl2br()` *after* escaping) and renders it via `dompdf/dompdf`
with `isRemoteEnabled`/`isJavascriptEnabled` both explicitly `false`.
The response streams the resulting bytes directly — `Storage::disk(...)`
is never touched, so there is no file, and therefore no storage path,
to ever appear in a response, log, or error message. This was a
deliberate dependency/environment review decision (Option B over
Option A/C) — see `docs/security.md#pdf-export-dependency-review--prototype-checkpoint-35`
for the full comparison against `barryvdh/laravel-dompdf`, `mpdf/mpdf`,
and headless-browser options (`spatie/browsershot`, wkhtmltopdf), all
of which were reviewed and rejected before this one was chosen.

**Checkpoint 37 adds a plain-text watermark banner** (no images,
nothing resembling an official seal) whenever the document's `status`
isn't `approved` — "DRAFT — NOT YET SUBMITTED FOR APPROVAL", "PENDING
APPROVAL — NOT YET APPROVED", "REJECTED — NOT APPROVED", or "ARCHIVED".
This was Option A from the approval-workflow gap analysis: PDF download
stays available at every status (a genuinely useful preview step before
submitting or after a rejection) rather than being blocked until
`approved`, but an unapproved letter is never visually indistinguishable
from a final one. See `docs/security.md#hr-document-approval-workflow-foundation-checkpoint-37`.

## HR Document Template Versions

Checkpoint 36 — HR Document Template Versioning Foundation.
`content_template` moved from `hr_document_templates` to a new
`hr_document_template_versions` table — `title`/`description`/`document_type`
deliberately stay template-only (your approved design). Reuses the
existing `hr_document_templates.*` permission set, plus one new key,
`hr_document_templates.publish` — see `docs/security.md`.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/hr-document-templates/{hrDocumentTemplate}/versions` | `hr_document_templates.view` | Paginated, scoped through `$template->versions()` (never a free query by template ID) — same pattern as `GET /policies/{policy}/versions` |
| `POST` | `/api/v1/hr-document-templates/{hrDocumentTemplate}/versions` | `hr_document_templates.update` | Always creates a `draft`; `version_number` auto-computed (`max(...) + 1`, including soft-deleted versions, so a discarded draft's number is never reused) |
| `GET` | `/api/v1/hr-document-template-versions/{hrDocumentTemplateVersion}` | `hr_document_templates.view` | |
| `PATCH` | `/api/v1/hr-document-template-versions/{hrDocumentTemplateVersion}` | `hr_document_templates.update` | `content_template` only; rejected (`422`) unless the version is currently `draft` |
| `POST` | `/api/v1/hr-document-template-versions/{hrDocumentTemplateVersion}/publish` | `hr_document_templates.publish` | Sets `status: published`, `published_at`/`published_by` (server-side only), demotes the template's previously-published version (if any) to `archived`, and updates `hr_document_templates.current_version_id` |
| `DELETE` | `/api/v1/hr-document-template-versions/{hrDocumentTemplateVersion}` | `hr_document_templates.delete` | Soft delete — only when `status` is `draft` (`422` for `published`/`archived`); old versions are never deleted |

### Response shape

```json
// GET /hr-document-templates/{id}/versions
{
  "data": [
    {
      "id": "01h...",
      "hr_document_template_id": "01h...",
      "version_number": 2,
      "content_template": "Dear {{employee.name}}, revised wording...",
      "status": "draft",
      "published_at": null,
      "published_by": null,
      "created_at": "2026-07-06T00:00:00+00:00",
      "updated_at": "2026-07-06T00:00:00+00:00"
    },
    {
      "id": "01h...",
      "hr_document_template_id": "01h...",
      "version_number": 1,
      "content_template": "Dear {{employee.name}}, original wording...",
      "status": "published",
      "published_at": "2026-07-05T00:00:00+00:00",
      "published_by": 7,
      "created_at": "2026-07-05T00:00:00+00:00",
      "updated_at": "2026-07-05T00:00:00+00:00"
    }
  ]
}
```

### Backfill (existing installs)

Three schema migrations plus one data migration
(`2026_07_06_150300_backfill_hr_document_template_versions.php`, using
the query builder directly, not the Eloquent model classes) ran once,
in this checkpoint's deploy: every existing `HrDocumentTemplate` gets a
`published` version 1 from its prior `content_template`, with
`current_version_id` set to match; every existing `HrGeneratedDocument`
gets `hr_document_template_version_id` backfilled to that same
version — accurate, not a guess, since before this checkpoint a
template only ever had one live `content_template`. `content_template`
is then dropped from `hr_document_templates`. See `docs/testing.md` for
how this was verified directly (rolling the migrations back, inserting
raw pre-checkpoint-shaped data, and replaying them forward) rather than
just assumed correct from a fresh install (which has no HR document
templates in its seed data to backfill in the first place).

## Recruitment & Applicant Tracking

Checkpoint 39 — Recruitment & Applicant Tracking Foundation. A simple
internal ATS foundation: job openings, applicants/applications, a
pipeline stage, and internal notes. Split permissions
(`job_openings.*`/`job_applications.*`) — see `docs/security.md` for
the full model.

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `/api/v1/job-openings` | `job_openings.view` | Paginated |
| `POST` | `/api/v1/job-openings` | `job_openings.create` | Always creates as `status: draft` regardless of body input |
| `GET` | `/api/v1/job-openings/{jobOpening}` | `job_openings.view` | |
| `PATCH` | `/api/v1/job-openings/{jobOpening}` | `job_openings.update` | `status` (if present) must be a legal transition from the record's *current* status — see `RecruitmentJobStatus::canTransitionTo()`. Moving to `open` sets `opened_at` server-side if unset; moving to `closed`/`cancelled` sets `closed_at` |
| `DELETE` | `/api/v1/job-openings/{jobOpening}` | `job_openings.delete` | Soft delete ("archive"); a non-terminal opening is transitioned to `cancelled` first |
| `GET` | `/api/v1/job-applications` | `job_applications.view` | Paginated |
| `POST` | `/api/v1/job-applications` | `job_applications.create` | One-step create — creates the `RecruitmentApplicant` (identity) and the `RecruitmentApplication` (this person's application to the given job) together in a single request, same single-step pattern as HR document template creation. Always starts `stage: applied`, `status: active`, `ready_for_conversion: false`. Body: `{"recruitment_job_id": "...", "first_name": "...", "last_name": "...", "email": "...", "phone": "..." (optional), "source": "..." (optional), "cover_letter": "..." (optional)}` |
| `GET` | `/api/v1/job-applications/{jobApplication}` | `job_applications.view` | |
| `PATCH` | `/api/v1/job-applications/{jobApplication}` | `job_applications.update` | Applicant contact fields (`first_name`/`last_name`/`email`/`phone`/`source`) and `cover_letter` only — `stage`/`ready_for_conversion` are never accepted here, see the dedicated actions below |
| `DELETE` | `/api/v1/job-applications/{jobApplication}` | `job_applications.delete` | Soft delete ("archive") |
| `POST` | `/api/v1/job-applications/{jobApplication}/notes` | `job_applications.add_note` | Internal-only — `visibility` is always `internal`, never accepted from request input. Body: `{"note": "..."}` |
| `PATCH` | `/api/v1/job-applications/{jobApplication}/stage` | `job_applications.update_stage` | Must be a legal transition from the application's *current* stage — see `ApplicationStage::canTransitionTo()`. Body: `{"stage": "..."}` |
| `PATCH` | `/api/v1/job-applications/{jobApplication}/ready-for-conversion` | `job_applications.mark_ready_for_conversion` | A milestone flag only — never creates an `Employee` row. Rejected (`422`) if the application's stage is `rejected`/`withdrawn`. Body: `{"ready_for_conversion": true|false}` |
| `POST` | `/api/v1/job-applications/{jobApplication}/convert-to-employee` | `job_applications.convert_to_employee` | **New in Checkpoint 40** — creates a real `Employee` row. Requires `stage: hired` AND `ready_for_conversion: true` AND not already converted (`422` otherwise). Runs in a database transaction. Body: `{"employee_number": "...", "employment_type": "...", "work_email": "..." (optional), "start_date": "..." (optional), "department_id"/"position_id"/"location_id": "..." (optional)}` — see "Candidate-to-employee conversion" below |
| `POST` | `/api/v1/job-applications/{jobApplication}/start-onboarding` | `lifecycle.create` | **New in Checkpoint 41** — creates a draft `LifecycleProcess` (type `onboarding`) for the application's already-converted employee and links it back via `onboarding_process_id`. No request body. Requires the application to already be converted, not already have started onboarding, and the employee to have no other active (`draft`/`in_progress`) onboarding process (`422` otherwise). Runs in a database transaction — see "Recruitment-to-onboarding handoff" below |

## Candidate-to-employee conversion

Checkpoint 40 — Candidate-to-Employee Conversion Foundation.
`POST /api/v1/job-applications/{id}/convert-to-employee` is gated by
one deliberately narrow permission (`job_applications.convert_to_employee`,
not also `employees.create`) and reuses `StoreEmployeeRequest`'s exact
uniqueness/active-lookup validation rules — never a looser parallel
rule set. See `docs/security.md` for the full model.

**Field mapping**: `first_name`/`last_name` come from the applicant
(not form-editable — they're the candidate's own submitted name).
`department_id`/`position_id`/`location_id`/`employment_type` pre-fill
from the job opening client-side when present, but every submitted
value is independently re-validated — the backend never trusts a
pre-filled value more than a manually-entered one.
`employee_number`/`start_date`/`work_email`/`status` are always manual
(no numbering scheme, no default start date, and `work_email` — though
pre-filled from the applicant's own email — must pass the same
per-tenant uniqueness check a normal employee create already requires).
`employment_type` is `required` on `Employee` but nullable on
`RecruitmentJob` — a job with none set simply forces manual selection,
same `required` rule `StoreEmployeeRequest` already has. `manager_employee_id`
is never part of this request — assigning a manager stays the exclusive
job of `PATCH /employees/{id}/manager`, unchanged from every other
employee-creation path.

**Server-controlled, never accepted from request input**:
`converted_employee_id`, `converted_at`, `converted_by`, `tenant_id`,
`created_by`, `updated_by`. The application row itself is never deleted
or overwritten by conversion — these three `converted_*` columns are
the only trace it leaves.

**Idempotent**: `converted_employee_id !== null` on the application
blocks any further conversion attempt with a `422`.

**No automatic side effects**: no `User` account, no role assignment,
no onboarding process is started. The frontend links to the existing
`/lifecycle/create?employeeId=...&type=onboarding` page as a manual
next step. A separate, explicit `POST /api/v1/users` action can create
and link a `User` account afterward (Checkpoint 43, see "User creation"
above) — but conversion itself still triggers nothing automatically.

## Recruitment-to-onboarding handoff

Checkpoint 41 — Recruitment-to-Onboarding Handoff Foundation.
`POST /api/v1/job-applications/{id}/start-onboarding` replaces
Checkpoint 40's static "Start onboarding" link with a real, tracked
action. See `docs/security.md` for the full model.

**No request body.** `employee_id`, `type: onboarding`, and
`status: draft` are entirely derived from the application's own
`converted_employee_id` — nothing is read from request input.

**Eligibility (`422` if any fails)**: the application must already be
converted (`converted_employee_id !== null`), must not have started
onboarding already (`onboarding_process_id === null`), and the
converted employee must have no other active (`draft`/`in_progress`)
onboarding process — a prior `completed`/`cancelled` one does not
block a new one.

**Gated by `lifecycle.create`**, reused from the existing Lifecycle
Processes endpoint (Checkpoint 33) rather than a new
recruitment-specific permission.

**Transactional and idempotent**: the new `LifecycleProcess` row and
the application's `onboarding_process_id` link are set together in one
transaction; a second start attempt on the same application is
rejected with `422`.

**Response**: the same `JobApplicationResource` shape, now including
`onboarding_process_id` and a nested `onboarding_process: {id, status}`
once set.

## Current limitations

- No export endpoint (`employees.export` permission is seeded but unused — explicitly out of scope this checkpoint).
- No bulk actions.
- No hierarchy for departments/positions/locations (Checkpoint 32) — flat lists only, no usage-count guard before archiving.
- No self-linking / invitation-token flow — linking a user to an employee is HR/admin-only, see `docs/security.md`.
- No employee profile self-update endpoint — `/me/employee` is read-only.
- Leave balances exist (Checkpoint 15) but have no accrual engine, carry-forward automation, half-day support, or public holiday calendar — see `docs/security.md#leave-balances-foundation`.
- No leave notifications or calendar integration.
- `reporting-tree` is depth-capped at 5 levels — no pagination/lazy-loading beyond it, no org chart UI.
- Manager-scoped leave approval is direct-reports-only — a manager cannot approve/reject an indirect (skip-level) report's leave; see `docs/security.md#manager-hierarchy-scoped-leave-approval`.
- No policy campaign automation, reminders, or escalations.
- No acknowledgement export/report endpoint (`policies.export_acknowledgements` seeded, unused).
- No document approval workflow endpoint — `documents.approve` permission and `approved_by`/`approved_at` columns are reserved, unused.
- Pagination uses Laravel's default page-number style; no cursor pagination or configurable page size yet.
- Session-based auth only — see "Authentication" above for the full future Sanctum/token plan.
- Task templates exist as of Checkpoint 42, but there's still no task dependencies/ordering, approval routing, notifications, or reminders for lifecycle processes/tasks (Checkpoint 33) — see `docs/security.md#onboarding--offboarding-foundation-checkpoint-33` and `docs/security.md#onboarding--offboarding-task-templates-foundation-checkpoint-42`.
- No standalone `GET` for a single lifecycle task — see "Lifecycle Processes & Tasks" above.
- No DOCX file generation, e-signature, automated sending, bulk generation, or employee self-service download for HR Documents (Checkpoint 34/35/36/37) — see `docs/security.md#hr-documents--letter-generation-foundation-checkpoint-34`. PDF export exists (Checkpoint 35) but is generate-on-demand only — every download re-renders from `rendered_content`, nothing is ever persisted. Template version history exists (Checkpoint 36) but has no diff/compare UI and no publish-approval workflow. A single-approver approval workflow exists (Checkpoint 37) but has no multi-level/routing approval and no notifications when a document changes state. A starter template library and safe duplication exist (Checkpoint 38 — seeded per-tenant, not a global/shared catalogue) but there's no AI-assisted generation, legal clause library, cross-tenant/global marketplace, template rating, or template import/export.
- Recruitment & Applicant Tracking (Checkpoint 39/40/41/42) — `resume_document_id` on `recruitment_applications` is reserved/unused (no upload endpoint), no applicant dedupe/merge-by-email (so two independent applications from the same real person could each be converted into separate employee rows), no public candidate portal, no CV parsing/AI screening, no interview scheduling, no offer approval/automation, no email notifications, and no bulk import/conversion. Candidate-to-employee conversion exists (Checkpoint 40) but does not itself create a `User` account or assign a role. A real onboarding handoff exists (Checkpoint 41, `start-onboarding`) and now pre-populates default tasks from the template catalog (Checkpoint 42) — but likewise doesn't itself create a `User` account, assign a role, or send notifications. A separate, explicit `POST /api/v1/users` action (Checkpoint 43) can create an account and link it to the converted employee afterward — deliberately never triggered automatically by either endpoint — see `docs/security.md#candidate-to-employee-conversion-foundation-checkpoint-40`, `docs/security.md#recruitment-to-onboarding-handoff-foundation-checkpoint-41`, `docs/security.md#onboarding--offboarding-task-templates-foundation-checkpoint-42`, and `docs/security.md#user-account-provisioning-checkpoint-43`.

## Future

- `employees.export` — CSV/Excel export, permission already reserved.
- Salary, bank details, medical information, disciplinary records — separate future checkpoints, each with their own sensitivity handling (per the master constitution — these are explicitly more sensitive than what this checkpoint covers).
- Leave, performance, lifecycle timeline — later HR modules building on this foundation.
- A real numbering scheme for `employee_number` (currently manually provided).
- Document approval workflow (`documents.approve`, `approved_by`/`approved_at` already reserved in the schema).
- Malware scanning on upload (currently type/size/content-detection validation only, no payload scanning).
- Cloud storage (S3 or similar) — local private disk only for now; the storage layer is already abstracted behind `storage_disk`/`storage_path` on the model, so this is a lower-effort future change than it might otherwise be.
- Onboarding documents / compliance document tracking (expiring work permits, certifications) — `document_categories.requires_expiry_date` and `employee_documents.expiry_date` already exist and are enforced at upload time; a dedicated "documents expiring soon" report/notification is future work, not built yet.
- Candidate documents (`applies_to` includes `candidate`) — a Recruitment module now exists (Checkpoint 39), but there's still no candidate-facing document upload/attachment endpoint (`recruitment_applications.resume_document_id` is reserved, unused).
- Self-linking / invitation-token flow for User ↔ Employee linking (currently HR/admin-only).
- Employee profile self-update — `/me/employee` is read-only; no endpoint lets an employee edit their own record.
- Indirect (skip-level) manager leave approval — `ManagerHierarchyService::isManagerOf()` already exists and could answer this; extending `resolveApprovalScope()` to use it is a deliberate future policy decision (see `docs/security.md`), not a technical blocker.
- Org chart UI, manager self-service dashboard, performance/probation review usage of the manager hierarchy — `ManagerHierarchyService` is built to be reusable for these, none exist yet.
- Leave balance accrual engine, carry-forward automation, half-day leave, business-day calculation, weekend/holiday exclusion — leave balances themselves now exist (Checkpoint 15), see `docs/security.md#leave-balances-foundation` for what's still missing.
- Manager team-balance view/dashboard — `ManagerHierarchyService` could support this, no endpoint exists yet.
- Leave notifications, email approval, and calendar integration.
- Real Leave/Document/Policy module UI — `/leave`, `/documents`, `/policies` are still permission-gated placeholders; Employees got a real UI in Checkpoint 17, reusing these same `/api/v1/employees` endpoints client-side (see `docs/architecture.md`).
- No department/location/position pickers in the Employee UI — no listing endpoint exists yet for those lookup tables.
- No manager-assignment or user-linking UI — `PATCH`/`DELETE /employees/{id}/manager` and `/link-user`/`/unlink-user` are unchanged, fully functional API endpoints (Checkpoints 11/13) with no frontend yet.
- Manager/Reports/Audit nav groups and pages, once they exist.
- Frontend test tooling (Vitest + React Testing Library), if component-level testing becomes valuable.
- Policy campaigns (bulk-assign a policy to a whole department/location, scheduled/recurring re-acknowledgement cycles).
- Email/notification reminders and escalations for overdue acknowledgements.
- Auto-reassignment when a policy is republished (currently: stale assignments are correctly rejected at acknowledge time, but nothing proactively creates a new pending row).
- Acknowledgement compliance reports/dashboard, and the export endpoint (`policies.export_acknowledgements` already reserved).
- Frontend UI for policy authoring, publishing, and the acknowledgement experience — this checkpoint is API-only, same as every module so far.
- A general tenant-level (non-employee-scoped) document table — would resolve the `employee_document_id` schema mismatch on `policy_versions` cleanly.
- Persisted PDF storage for HR Documents (Option C — generate once, save to the private disk, attach via the existing, still-unused `hr_generated_documents.employee_document_id` column), if re-downloading identical bytes without re-rendering or Document Repository integration is ever needed. On-demand generation (Option B) shipped in Checkpoint 35 — see above.
- DOCX file generation for HR Documents, if a real need for an editable (not just final) format is shown.
- A diff/compare view between two template versions, and an approval/review step before publishing — both explicitly out of scope for Checkpoint 36's versioning foundation.
- Multi-level/routing approval for HR generated documents, notifications/reminders on submit/approve/reject, and a cryptographic/visual (not just plain-text) watermark — all explicitly out of scope for Checkpoint 37's single-approver approval workflow.
- Template versioning, e-signature, and approval-routing workflows for HR Documents, once a real need is scoped — deliberately not built in Checkpoint 34.
- Bulk HR document generation (e.g., the same letter for a whole department) and employee self-service download.
- AI-assisted template/document generation, a legal clause library, a global/shared template marketplace across tenants, template rating, and template import/export — all explicitly out of scope for Checkpoint 38's starter template library and duplication feature.
- Automatic `User` account creation and role assignment at conversion time, and an "start onboarding automatically" checkbox (Option B) — both deliberately deferred from Checkpoint 40's conversion foundation (manual "Start onboarding" link only).
- A public candidate portal, job-board posting, CV parsing/AI screening, interview scheduling, offer approval/automation, and email notifications for Recruitment — all explicitly out of scope for Checkpoint 39/40's foundation.
- Applicant dedupe/merge-by-email carried through to conversion, resume/CV upload for applications (`resume_document_id` already reserved in the schema), offer-letter automation reusing HR Documents, and bulk conversion — future work for Recruitment.
