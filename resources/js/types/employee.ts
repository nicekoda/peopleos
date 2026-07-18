/**
 * Mirrors App\Http\Resources\EmployeeResource exactly — see
 * docs/api.md#employees. personal_email/phone are `null` whenever the
 * viewer lacks employees.view_sensitive; this type can't distinguish
 * that from "genuinely empty" on its own (see Show.tsx's rendering
 * rule, which uses the viewer's own permission list for the
 * accompanying microcopy — a cosmetic decision, never a re-exposure of
 * anything the backend already decided to hide).
 */
export interface Employee {
    id: string;
    employee_number: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    preferred_name: string | null;
    full_name: string;
    work_email: string | null;
    personal_email: string | null;
    phone: string | null;
    status: 'draft' | 'active' | 'inactive' | 'terminated';
    employment_type: 'full_time' | 'part_time' | 'contractor' | 'intern' | 'consultant';
    department_id: string | null;
    location_id: string | null;
    position_id: string | null;
    // Resolved names (Checkpoint 32) — always present alongside the raw
    // IDs above (EmployeeController eager-loads all three), null only
    // when the employee genuinely has no department/location/position
    // assigned.
    department: { id: string; name: string } | null;
    location: { id: string; name: string } | null;
    position: { id: string; name: string } | null;
    manager_employee_id: string | null;
    // Checkpoint 43 — null unless a user account already exists and is
    // linked; drives the Employee detail page's "create/view user
    // account" affordance.
    linked_user: { id: number; name: string } | null;
    start_date: string | null;
    probation_end_date: string | null;
    confirmation_date: string | null;
    created_at: string | null;
    updated_at: string | null;
    // Checkpoint 51 — this employee's own active custom field values
    // (field_key => value); a disabled field or one the viewer lacks
    // tier access to is simply absent, same as recruitment_applicant/
    // job_application already work.
    custom_field_values?: Record<string, unknown>;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
    };
    links?: {
        next: string | null;
        prev: string | null;
    };
}

/**
 * The allowlisted fields Create/Edit forms may submit — deliberately a
 * narrower type than Employee itself (Refinement 3). Never built by
 * spreading a full Employee object; manager_employee_id/user-link
 * fields/tenant_id/created_by/updated_by remain structurally absent,
 * matching what Store/UpdateEmployeeRequest actually accept.
 * department_id/location_id/position_id were added Checkpoint 32, now
 * that real lookup APIs exist — always sent as an explicit `null` when
 * cleared, never omitted, so clearing the field on Edit genuinely
 * unassigns it rather than silently leaving the old value in place
 * (StoreEmployeeRequest/UpdateEmployeeRequest's rules are `nullable`
 * with no `sometimes`, same reasoning as Leave Type's
 * max_days_per_year — see docs/security.md).
 */
export interface EmployeeFormPayload {
    employee_number: string;
    first_name: string;
    middle_name: string;
    last_name: string;
    preferred_name: string;
    work_email: string;
    personal_email: string;
    phone: string;
    employment_type: Employee['employment_type'] | '';
    status: Employee['status'] | '';
    department_id: string;
    location_id: string;
    position_id: string;
    start_date: string;
    probation_end_date: string;
    confirmation_date: string;
}
