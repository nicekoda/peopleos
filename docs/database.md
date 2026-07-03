# Database

PostgreSQL. Migrations only — no manual schema changes.

## Conventions

- **Primary keys**: ULIDs (`$table->ulid('id')->primary()`) for `tenants`.
  `users`, `roles`, `permissions`, and their pivot tables deliberately use
  Laravel's default bigint auto-increment (a Checkpoint 4 decision — not
  changed to ULID even though `users` is now central to the app).
  **Internal database IDs may remain bigint.** The rule that matters is
  about what's *exposed*, not the storage type: public-facing links,
  invitation links, external portal links, document links, and any other
  sensitive reference visible outside an authenticated session must never
  expose a raw internal ID. Future modules that need a public-facing
  identifier must use a secure token, a ULID/UUID public ID (separate
  column from the bigint PK if needed), a signed URL, or a configured
  reference code — not `/employees/482`.
- **Tenant-owned tables** must include `tenant_id` (ULID, references
  `tenants.id`) and use the `BelongsToTenant` trait — see
  [`architecture.md`](architecture.md). `leave_types`/`leave_requests`
  (Checkpoint 12) follow this convention with ULID primary keys, same as
  every other tenant-owned business table (`employees`, `policies`,
  `document_categories`, ...) — the bigint exception remains scoped to
  `users`/`roles`/`permissions`/their pivots only, unchanged since the
  Checkpoint 4 decision. No new inconsistency introduced.
- **Soft deletes** on tables where records should be recoverable /
  audit-relevant rather than hard-deleted (e.g. `tenants`).
- **`created_by` / `updated_by`**: not yet added anywhere, including
  `users` itself — attributing a user's own creation needs an actor
  (creating admin or self-registration flow) that doesn't exist yet.
  Revisit once an admin-creates-user flow is built.

## Tables

### `tenants`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `name` | string | Display name |
| `subdomain` | string(63), unique | DNS label max length; used for tenant resolution |
| `status` | string | `active` \| `suspended` \| `inactive` (see `Tenant::STATUS_*`) |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable | Soft delete |

Seeded locally via `TenantSeeder` with three demo tenants (`uesl`,
`airpeace`, `ibom`) matching the local hosts-file subdomains — placeholder
names, rename directly in the table until tenant management tooling
exists.

### `users`

Laravel's default table (`id` bigint, `name`, `email` unique, `password`,
`email_verified_at`, `remember_token`, timestamps), extended with:

| Column | Type | Notes |
|---|---|---|
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | Null only for platform admins |
| `status` | string, default `active` | `active` \| `inactive` \| `suspended` (`User::STATUS_*`) |
| `is_platform_admin` | boolean, default `false` | See `docs/security.md` |
| `last_login_at` | timestamp, nullable | Set on successful login |
| `last_login_ip` | string(45), nullable | Set on successful login |
| `deleted_at` | timestamp, nullable | Soft delete |

Postgres `CHECK` constraint `users_platform_admin_tenant_consistency`
enforces `is_platform_admin` and `tenant_id` are always consistent (skipped
on SQLite; see `docs/security.md` for the app-layer equivalent).

See [`security.md`](security.md) for the full login/tenant-boundary design
and local demo credentials.

### `permissions`

A global catalog — not tenant-scoped itself. What's tenant-scoped is the
*assignment* of a permission to a role or user.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `key` | string, unique | e.g. `employees.view` — see naming convention in `security.md` |
| `category` | string | Derived grouping, e.g. `employees`, `platform` |
| `is_platform_permission` | boolean, default `false` | Platform-level vs. tenant-level |
| `description` | text, nullable | |

### `roles`

Each tenant owns its **own** copy of every tenant role — roles are not
shared templates. See `security.md` for why.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | Null only for platform roles |
| `is_platform_role` | boolean, default `false` | |
| `name` | string | |
| `slug` | string | Unique per tenant (`tenant_id`,`slug`); platform roles unique among themselves via a partial index (`WHERE tenant_id IS NULL`) |
| `description` | text, nullable | |
| `deleted_at` | timestamp, nullable | Soft delete |

Postgres `CHECK` constraint `roles_platform_tenant_consistency` mirrors the
same rule as `users` (skipped on SQLite; app-layer guard covers it there).

### `role_permission`, `user_role` (pivots)

Standard many-to-many pivots, each with a unique composite constraint to
prevent duplicate assignment. `user_role` additionally stores a
denormalized `tenant_id` (copied from the role at assignment time) so
tenant-scoped queries don't need a join through `roles`.

### `user_permissions`

Direct permission grants, outside role assignment.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | |
| `user_id` | bigint, FK → `users.id` `CASCADE` | |
| `permission_id` | bigint, FK → `permissions.id` `CASCADE` | |
| `granted_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `reason` | text, nullable | |
| `created_at` | timestamp | No `updated_at` — see `security.md` |

Unique (`user_id`,`permission_id`) — a user has at most one direct grant
per permission. Revocation is a hard delete of the row, not a soft delete
or status flag.

### `audit_logs`

Append-only — no `updated_at`, no `deleted_at`. Enforced at the model
layer too (`AuditLog::save()`/`delete()` throw on any update/delete
attempt), not just by the absence of an edit/delete UI. See
[`security.md`](security.md#audit-logging) for what's currently logged
and the masking rules.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, primary key | |
| `tenant_id` | ulid, nullable, FK → `tenants.id` `RESTRICT` | Null for platform-level events |
| `actor_user_id` | bigint, nullable, FK → `users.id` `SET NULL` | Null for system/seeder-driven events |
| `actor_type` | string, nullable | `user` \| `system`, inferred from `actor_user_id` if not given |
| `action` | string, required | e.g. `login.success`, `role.assigned` |
| `module` | string, required | e.g. `auth`, `rbac` |
| `auditable_type` / `auditable_id` | string, nullable | Polymorphic reference — `auditable_id` is a string (not a real FK) since referenced models have mixed PK types (bigint today, ULID for future tenant-owned models) |
| `target_user_id` | bigint, nullable, FK → `users.id` `SET NULL` | The user an action was performed *on* |
| `description` | text, nullable | |
| `old_values` / `new_values` | json, nullable | Masked for known-sensitive keys — see `security.md` |
| `metadata` | json, nullable | Not masked — don't put sensitive data here either |
| `ip_address` | string(45), nullable | |
| `user_agent` | text, nullable | |
| `severity` | string, nullable, default `info` | `info` \| `warning` \| `critical` |
| `created_at` | timestamp | No `updated_at` |

Indexed on `action`, `module`, `(auditable_type, auditable_id)`, plus the
implicit indexes from the `tenant_id`/`actor_user_id`/`target_user_id`
foreign keys.

### `departments`, `locations`, `positions`

Minimal, identical shape — `id` (ulid, PK), `tenant_id` (ulid, FK →
`tenants.id` `RESTRICT`), `name`, timestamps, soft delete, unique
(`tenant_id`, `name`). No CRUD endpoints yet; they exist to give
`employees.department_id`/`location_id`/`position_id` something real to
validate against. First real usage of `BelongsToTenant` on actual business
data (Checkpoint 2 only exercised it against a throwaway test fixture).

### `employees`

The first real tenant-owned HR business record. One table — deliberately
not split into `employee_contact_details`/`employee_employment_details`/
etc. (per your "don't over-engineer" guidance; nothing in this checkpoint
needed a separate table, and no emergency-contact fields were specified).

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `employee_number` | string | Unique per tenant. **Manually provided, not auto-generated** — no numbering-scheme feature exists yet |
| `first_name` / `last_name` | string, required | |
| `middle_name` / `preferred_name` | string, nullable | |
| `work_email` | string, nullable | Unique per tenant (nullable — Postgres/SQLite allow multiple NULLs in a composite unique, which is the desired behavior here, unlike the platform-role-slug case) |
| `personal_email` | string, nullable | **Sensitive** — see `security.md` |
| `phone` | string, nullable | **Sensitive** — see `security.md` |
| `status` | string, default `draft` | `App\Enums\EmployeeStatus`: `draft` \| `active` \| `inactive` \| `terminated` |
| `employment_type` | string, required | `App\Enums\EmploymentType`: `full_time` \| `part_time` \| `contractor` \| `intern` \| `consultant` |
| `department_id` / `location_id` / `position_id` | ulid, nullable, FK `SET NULL` | Must belong to the same tenant — validated in the FormRequest, not just a DB constraint |
| `manager_employee_id` | ulid, nullable, FK → `employees.id` `SET NULL` | Self-referencing — see migration note below. **Only writable via `PATCH`/`DELETE /employees/{employee}/manager`** as of Checkpoint 13 — removed from `StoreEmployeeRequest`/`UpdateEmployeeRequest` entirely, see `security.md` |
| `start_date` / `probation_end_date` / `confirmation_date` | date, nullable | `probation_end_date` must be ≥ `start_date` when both provided |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | Set from the authenticated user, never request input |
| `user_id` | bigint, nullable, **unique**, FK → `users.id` `SET NULL` | Checkpoint 11 — links this employee to an authentication account. Unique constraint enforces the 1:1 relationship from both directions at the schema level: Postgres itself rejects a second employee claiming a `user_id` already in use. Set only by `EmployeeUserLinkController`, never by the general update endpoint — see `security.md` |
| `linked_at` | timestamp, nullable | Set when `user_id` is linked, cleared on unlink |
| `linked_by` | bigint, nullable, FK → `users.id` `SET NULL` | The admin/HR user who performed the link, for audit purposes beyond the `audit_logs` entry itself |
| `deleted_at` | timestamp, nullable | Soft delete — the `DELETE` endpoint soft-deletes, never hard-deletes |

**Migration note — self-referencing FK:** `manager_employee_id` can't have
its FK constraint added within the same `CREATE TABLE` statement that
creates `employees` (Postgres: `there is no unique constraint matching
given keys for referenced table "employees"` — the primary key isn't
visible yet to a same-statement self-reference). Fixed by defining the
column inline and adding the FK constraint in a separate `Schema::table()`
call immediately after. Hit and fixed during this checkpoint — a real
error, not a false start.

No payroll, salary, bank details, medical information, disciplinary
information, or documents in this table — deliberately deferred to
separate, more sensitive future checkpoints per your instruction.

### `document_categories`

Tenant-owned lookup table, same base pattern as `departments`/
`locations`/`positions`, extended with fields specific to document
governance. Unlike those three, this one **has a full management API**
as of Checkpoint 9 — see [`api.md`](api.md) and
[`security.md`](security.md#document-category-management).

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `name` / `slug` | string | Unique per tenant |
| `description` | text, nullable | |
| `applies_to` | string, default `employee` | `App\Enums\DocumentAppliesTo`: `employee` \| `tenant` \| `policy` \| `candidate` \| `general` — only `employee` is actually used yet (no tenant/policy/candidate document flows exist) |
| `is_sensitive` | boolean, default `false` | Documents uploaded under a sensitive category inherit this on upload — see `security.md` |
| `is_required` | boolean, default `false` | Reserved — no onboarding/completeness-checking feature reads this yet |
| `requires_expiry_date` | boolean, default `false` | Enforced at upload time — see `docs/api.md` validation rules |
| `status` | string, default `active` | `App\Enums\DocumentCategoryStatus`: `active` \| `inactive` |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | No category-management endpoint exists yet, so these are unused in practice this checkpoint |
| `deleted_at` | timestamp, nullable | Soft delete |

### `employee_documents`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `employee_id` | ulid, FK → `employees.id` `CASCADE` | |
| `document_category_id` | ulid, nullable, FK → `document_categories.id` `SET NULL` | Must belong to the same tenant — validated in the FormRequest |
| `title` / `description` | string / text, nullable | |
| `original_filename` | string | **Display metadata only** — never used for storage or serving |
| `stored_filename` | string | Randomized (`Str::random(40)` + extension) — never derived from the original filename |
| `storage_disk` | string | `local` (`storage/app/private`, not web-accessible) |
| `storage_path` | string | Never exposed via the API — see `security.md` |
| `mime_type` / `file_extension` / `file_size` | string / string / bigint | From the actual uploaded file, not client-declared values alone |
| `checksum` | string(64), nullable | SHA-256 of file content |
| `status` | string, default `active` | `App\Enums\DocumentStatus`: `active` \| `archived` \| `rejected` — **no `deleted` value**, see note below |
| `is_sensitive` | boolean, default `false` | Inherited from `document_category.is_sensitive` at upload time |
| `issue_date` / `expiry_date` | date, nullable | `expiry_date` must be ≥ `issue_date`; required if the category has `requires_expiry_date` |
| `uploaded_by` / `approved_by` / `approved_at` | bigint/bigint/timestamp, nullable | `approved_by`/`approved_at` are placeholder columns — no approval workflow endpoint exists yet |
| `deleted_at` | timestamp, nullable | Soft delete — the `DELETE` endpoint soft-deletes only |

**Why `status` has no `deleted` value:** the spec listed
`active/archived/deleted/rejected`, but this table also has `deleted_at`
(soft delete). Using both would let a row be `status=active` *and*
soft-deleted simultaneously — contradictory. `deleted_at` is the actual
delete mechanism; `status` covers the remaining three states.

### `policies`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `title` / `slug` | string | Both unique per tenant |
| `code` | string, nullable | Unique per tenant where provided |
| `description` / `category` | text/string, nullable | `category` is a plain free-text field, not a FK — no lookup table for policy categories was requested |
| `owner_user_id` | bigint, nullable, FK → `users.id` `SET NULL` | Must belong to the same tenant — validated in the FormRequest |
| `status` | string, default `draft` | `App\Enums\PolicyStatus`: `draft` \| `published` \| `archived` — **shared with `policy_versions.status`**, values are identical for both |
| `current_version_id` | ulid, nullable, FK → `policy_versions.id` `SET NULL` | Added via a follow-up migration (see note below) |
| `effective_date` / `review_date` | date, nullable | `review_date` ≥ `effective_date` when both provided |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `deleted_at` | timestamp, nullable | Soft delete |

**Migration note — forward-referencing FK:** `current_version_id` points
at a table (`policy_versions`) that doesn't exist yet when `policies` is
created. Same pattern as `employees.manager_employee_id`'s self-reference
workaround: the column is added as a plain nullable ULID in the
`create_policies_table` migration, and the actual FK constraint is added
in a separate `add_current_version_fk_to_policies_table` migration once
`policy_versions` exists.

### `policy_versions`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `policy_id` | ulid, FK → `policies.id` `CASCADE` | |
| `version_number` | unsigned int | Unique per (`tenant_id`, `policy_id`) — computed as `max(existing) + 1` by the controller, not a DB sequence, since it must be scoped per policy |
| `title` / `summary` / `content` | string / text / longtext, nullable except title | `content` is the primary path for this checkpoint — see the `employee_document_id` note below |
| `employee_document_id` | ulid, nullable, FK → `employee_documents.id` `SET NULL` | **Known schema mismatch** — see `security.md` |
| `status` | string, default `draft` | Shares `App\Enums\PolicyStatus` with `policies.status` |
| `published_by` / `published_at` | bigint/timestamp, nullable | Set only by the publish action |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `deleted_at` | timestamp, nullable | Soft delete — old published versions are archived, never deleted |

### `policy_acknowledgements`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `policy_id` / `policy_version_id` | ulid, FK `CASCADE` | |
| `employee_id` | ulid, FK → `employees.id` `CASCADE` | |
| `assigned_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `assigned_at` | timestamp, **required** | |
| `due_date` | date, nullable | |
| `acknowledged_at` | timestamp, nullable | |
| `acknowledgement_status` | string, default `pending` | `App\Enums\AcknowledgementStatus`: `pending` \| `acknowledged` \| `overdue` \| `waived` |
| `acknowledgement_method` | string, nullable | `App\Enums\AcknowledgementMethod`: `web` \| `admin_recorded` — meaningfully distinguished as of Checkpoint 11: `web` for genuine self-acknowledgement via the caller's own linked employee, `admin_recorded` when recorded on behalf of a different employee (requires `policies.assign`), see `security.md` |
| `ip_address` / `user_agent` | string(45)/text, nullable | |

**No soft deletes on this table** — not in the original field list, and
there's no delete endpoint; these are compliance-evidence records.
Unique (`tenant_id`, `policy_version_id`, `employee_id`) prevents
duplicate acknowledgement rows for the same employee + version at the
database level, not just app-layer checking.

### A real bug found and fixed in this checkpoint, affecting 3 existing models

`Employee` and `DocumentCategory` (from Checkpoints 6 and 9) both
excluded `created_by`/`updated_by` from `$fillable`, on the reasoning
that "they're not accepted as request input." That reasoning is correct,
but the *implementation* was wrong: excluding a column from `$fillable`
also blocks the controller's own trusted, explicit assignment via
`Model::create($data)` — Eloquent silently drops it. **Every employee and
document category created via the API since those checkpoints has had
`created_by`/`updated_by` = `NULL`**, despite the controller code
appearing to set them correctly. Confirmed directly (not just reasoned
about) with a standalone script before fixing. Fixed by adding both
columns to `$fillable` on `Employee`, `DocumentCategory`, `Policy`, and
`PolicyVersion` — they're still never accepted from *request* input (not
present in any FormRequest's validated rules), only from the controller's
own explicit assignment, which is the actual security boundary that
matters.

### `leave_types`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `name` / `slug` | string | Both unique per tenant |
| `description` | text, nullable | |
| `is_paid` | boolean, default `true` | |
| `requires_approval` | boolean, default `true` | Stored but not yet enforced — every leave request goes through the same `draft → pending → approved/rejected` flow regardless of this flag this checkpoint; see `security.md` for the limitation |
| `requires_document` | boolean, default `false` | Stored but not enforced — no document-attachment capability exists on leave requests yet |
| `max_days_per_year` | unsigned smallint, nullable | Stored but not enforced — no leave-balance/accrual engine exists yet, see `security.md` "Future" |
| `status` | string, default `active` | `App\Enums\LeaveTypeStatus`: `active` \| `inactive` |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `deleted_at` | timestamp, nullable | Soft delete — the `DELETE` endpoint soft-deletes only, same pattern as `document_categories` |

### `leave_requests`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `employee_id` | ulid, FK → `employees.id` `CASCADE` | Never accepted from request input — always resolved from the caller's own linked employee, see `security.md` |
| `leave_type_id` | ulid, FK → `leave_types.id` `RESTRICT` | `RESTRICT`, not `SET NULL`, because the column is required (not nullable) — matches the `tenant_id` FK convention for a required lookup reference |
| `start_date` / `end_date` | date, required | `end_date` must be ≥ `start_date` |
| `total_days` | unsigned smallint | **Always server-computed** (inclusive calendar days between `start_date`/`end_date`) — never trusted from request input, even if present in the body |
| `reason` | text, nullable | Free text — masked in audit log old/new-value snapshots, see `security.md` |
| `status` | string, default `draft` | `App\Enums\LeaveRequestStatus`: `draft` \| `pending` \| `approved` \| `rejected` \| `cancelled` |
| `submitted_at` | timestamp, nullable | Set by the `submit` action |
| `approved_by` / `approved_at` | bigint/timestamp, nullable, FK `SET NULL` | Set by the `approve` action |
| `rejected_by` / `rejected_at` | bigint/timestamp, nullable, FK `SET NULL` | Set by the `reject` action |
| `rejection_reason` | text, nullable | Required by `RejectLeaveRequestRequest` when rejecting; masked in audit logs same as `reason` |
| `cancelled_by` / `cancelled_at` | bigint/timestamp, nullable, FK `SET NULL` | **Not in the original field list** — `cancelled_by` added per explicit refinement request for accountability, same reasoning as `approved_by`/`rejected_by` (a timestamp alone records *when*, not *who*) |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `deleted_at` | timestamp, nullable | Soft delete |

Indexed on (`tenant_id`,`employee_id`) and (`tenant_id`,`status`) — the
two dimensions every query filters on (own-employee scoping, and
pending-requests-for-approval listing).

**`total_days` calculation — inclusive calendar days, not business
days.** Weekends and public holidays are counted. Chosen as the simplest
correct default for this checkpoint; no business-day calculation,
weekend exclusion, or holiday calendar exists. See `security.md` for the
full list of deferred improvements (business-day calculation, half-day
leave, leave balances/accrual).

### Checkpoint 11 `$fillable` review — one more real gap found

The audit above was scoped to models with a `created_by`/`updated_by`
pattern. This checkpoint's required broader review (9 named models)
found one more: `User`'s `#[Fillable(...)]` attribute was missing
`email_verified_at`, confirmed the same way — an isolated `create()`
reproduction that persisted `NULL` despite the value being passed
explicitly. See [`architecture.md`](architecture.md#required-fillable-quality-review--one-real-bug-found)
and [`security.md`](security.md#user--employee-linking) for the full
writeup. `Department`, `Location`, `Position`, `EmployeeDocument` were
also checked and found correct.

### Checkpoint 15 quality review — same fixes applied proactively, plus a new one

`LeaveBalance` was built with the established fixes from the start:
`employee_id`/`leave_type_id` validated via explicit tenant-scoped
closures (not raw `Rule::exists()`), `leave_type_id` additionally
excludes inactive/soft-deleted leave types, `used_days`/`pending_days`
explicitly defaulted server-side before `create()` and structurally
excluded from `UpdateLeaveBalanceRequest` entirely, every balance
mutation wrapped in `DB::transaction()` with `lockForUpdate()`. One new
issue found during implementation (not a repeat of an old one): see
`architecture.md`'s note on the `LeaveRequestFactory`/`recycle()` test-
fixture fix — a latent tenant-scoping gap in test fixtures, not
production code, but the same underlying lesson (a relation silently
scoped to the wrong tenant, found only once something finally
dereferenced it for real).

### Checkpoint 12 quality review — no new issues, same fixes applied proactively

Per your explicit instruction (given the `$fillable`/`Rule::exists()`/
`Model::create()` bug history above), `LeaveType` and `LeaveRequest`
were built with all three known fixes applied from the start rather than
found after the fact:

1. `leave_type_id` validation in `StoreLeaveRequestRequest`/
   `UpdateLeaveRequestRequest` uses `Rule::exists()` with an explicit
   `where('status', 'active')->whereNull('deleted_at')` closure — the
   same fix already required for `document_category_id` in Checkpoint 9.
2. `status`, `is_paid`, `requires_approval`, `requires_document` on
   `LeaveType`, and `status`/`total_days` on `LeaveRequest`, are
   explicitly defaulted in the controller before `create()` — the same
   fix already required for `DocumentCategory` in Checkpoint 9.
3. `created_by`/`updated_by`/`employee_id`/`total_days` and every
   approval/rejection/cancellation field are included in both models'
   `$fillable` from the start, with the same "trusted controller
   assignment, never accepted from request input" comment pattern used
   everywhere else.

### `leave_balances`

| Column | Type | Notes |
|---|---|---|
| `id` | ulid, primary key | |
| `tenant_id` | ulid, FK → `tenants.id` `RESTRICT` | |
| `employee_id` | ulid, FK → `employees.id` `CASCADE` | |
| `leave_type_id` | ulid, FK → `leave_types.id` `RESTRICT` | Only leave types with `max_days_per_year` set ever get a balance row — see `security.md` |
| `year` | unsigned smallint | Per the leave request's `start_date` year — see "Balance year rule" in `security.md` |
| `entitlement_days` | decimal(6,2) | Seeded from `leave_types.max_days_per_year` on auto-creation; admin-editable via `PATCH` |
| `used_days` | decimal(6,2), default `0` | Only ever set by `LeaveBalanceService`, never by request input |
| `pending_days` | decimal(6,2), default `0` | Shared aggregate across every pending request for this employee/leave-type/year — not a per-request ledger, see `architecture.md` |
| `carried_forward_days` | decimal(6,2), default `0` | Admin-editable; no carry-forward *automation* exists yet |
| `adjustment_days` | decimal(6,2), default `0` | Admin-editable, gated by `leave_balances.adjust` specifically (not just `.update`); can be negative (a correction/debit) |
| `created_by` / `updated_by` | bigint, nullable, FK → `users.id` `SET NULL` | |
| `deleted_at` | timestamp, nullable | Soft delete |

`decimal(6,2)` for every day-count field (not integer) — half-day leave
isn't built this checkpoint, but this makes it a data-only change later
rather than a schema migration too, per your explicit recommendation.

**`available_days` is not a column.** Computed on read via
`LeaveBalance::availableDays()`:
`entitlement_days + carried_forward_days + adjustment_days - used_days
- pending_days`. Deliberately not cached/stored, to avoid ever serving
a stale value — see `security.md`.

**Uniqueness is a partial index, not a plain composite unique
constraint** — `UNIQUE (tenant_id, employee_id, leave_type_id, year)
WHERE deleted_at IS NULL`, the same pattern already used for
`roles.slug`'s platform-role uniqueness (see that migration). A plain
composite unique would block recreating a balance after the original
row was soft-deleted; the partial index excludes soft-deleted rows from
the uniqueness check entirely.

### Checkpoint 13 — no schema change, existing field reused as-is

`manager_employee_id` (added in Checkpoint 6) needed no migration this
checkpoint — the column, its `SET NULL` FK, and the self-referencing FK
migration workaround were already correct. What was missing was
entirely at the validation/service layer: no cycle detection, no
active-status check, and a raw `Rule::exists()` on the general update
endpoint that didn't exclude soft-deleted managers (the same bug class
as `document_category_id`/`leave_type_id` before it). See
`security.md#manager-hierarchy` for the full fix — `AssignManagerRequest`
+ `ManagerHierarchyService`, with the general update endpoint's
`manager_employee_id` field removed entirely rather than patched in
place.
