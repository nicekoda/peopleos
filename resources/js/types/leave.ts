/**
 * Mirrors App\Http\Resources\LeaveRequestResource/LeaveTypeResource/
 * LeaveBalanceResource exactly — see docs/api.md#leave-management.
 * Neither LeaveRequestResource nor LeaveType lookups include an
 * employee/user *name* — only IDs. See Show.tsx/Index.tsx for how this
 * is handled honestly rather than showing a raw ID as if it were a
 * finished design (Refinement 1).
 */
export type LeaveRequestStatus = 'draft' | 'pending' | 'approved' | 'rejected' | 'cancelled';

export interface LeaveRequest {
    id: string;
    employee_id: string;
    leave_type_id: string;
    start_date: string;
    end_date: string;
    total_days: number;
    reason: string | null;
    status: LeaveRequestStatus;
    submitted_at: string | null;
    approved_by: number | null;
    approved_at: string | null;
    rejected_by: number | null;
    rejected_at: string | null;
    rejection_reason: string | null;
    cancelled_by: number | null;
    cancelled_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface LeaveType {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    is_paid: boolean;
    requires_approval: boolean;
    requires_document: boolean;
    max_days_per_year: number | null;
    status: 'active' | 'inactive';
    created_at: string | null;
    updated_at: string | null;
}

export interface LeaveBalance {
    id: string;
    employee_id: string;
    leave_type_id: string;
    year: number;
    entitlement_days: number;
    used_days: number;
    pending_days: number;
    carried_forward_days: number;
    adjustment_days: number;
    available_days: number;
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
 * The allowlisted fields POST /leave-requests accepts (Refinement 8/
 * plan item 3) — total_days/employee_id/status/tenant_id are never
 * fields here at all, matching StoreLeaveRequestRequest exactly.
 */
export interface LeaveRequestFormPayload {
    leave_type_id: string;
    start_date: string;
    end_date: string;
    reason: string;
}
