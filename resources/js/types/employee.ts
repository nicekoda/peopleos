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
    manager_employee_id: string | null;
    start_date: string | null;
    probation_end_date: string | null;
    confirmation_date: string | null;
    created_at: string | null;
    updated_at: string | null;
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
 * spreading a full Employee object; department_id/location_id/
 * position_id/manager_employee_id/user-link fields/tenant_id/
 * created_by/updated_by are structurally absent, matching what
 * Store/UpdateEmployeeRequest actually accept.
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
    start_date: string;
    probation_end_date: string;
    confirmation_date: string;
}
