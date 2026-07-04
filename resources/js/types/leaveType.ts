// Mirrors LeaveTypeResource exactly (Checkpoint 25) — no
// tenant_id/created_by/updated_by/deleted_at.
export type LeaveTypeStatus = 'active' | 'inactive';

export interface LeaveType {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    is_paid: boolean;
    requires_approval: boolean;
    requires_document: boolean;
    max_days_per_year: number | null;
    status: LeaveTypeStatus;
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
}

/**
 * Allowlisted for the create/edit forms (Refinement 3). `slug` is
 * auto-generated from `name` server-side, never a form field. `status`
 * is only ever sent from the Edit form. `max_days_per_year` is a plain
 * string in form state (empty string = "unlimited") — see Create.tsx/
 * Edit.tsx for how the empty case is handled differently on each
 * (Refinement 4): omitted on create, explicit `null` on edit.
 */
export interface LeaveTypeFormPayload {
    name: string;
    description: string;
    is_paid: boolean;
    requires_approval: boolean;
    requires_document: boolean;
    max_days_per_year: string;
    status: LeaveTypeStatus | '';
}
