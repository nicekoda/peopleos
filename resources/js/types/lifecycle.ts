// Mirrors LifecycleProcessResource/LifecycleTaskResource exactly
// (Checkpoint 33) — no tenant_id/created_by/updated_by/deleted_at.
export type LifecycleProcessType = 'onboarding' | 'offboarding';
export type LifecycleProcessStatus = 'draft' | 'in_progress' | 'completed' | 'cancelled';
export type LifecycleTaskStatus = 'pending' | 'in_progress' | 'completed' | 'skipped';

export interface LifecycleProcess {
    id: string;
    employee_id: string;
    employee?: { id: string; full_name: string } | null;
    type: LifecycleProcessType;
    status: LifecycleProcessStatus;
    started_at: string | null;
    due_date: string | null;
    completed_at: string | null;
    tasks?: LifecycleTask[];
    created_at: string | null;
    updated_at: string | null;
}

export interface LifecycleTask {
    id: string;
    process_id: string;
    title: string;
    description: string | null;
    assigned_to_user_id: number | null;
    assigned_to?: { id: number; name: string } | null;
    status: LifecycleTaskStatus;
    due_date: string | null;
    completed_at: string | null;
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
 * Allowlisted for the Create form — employee_id/type are fixed at
 * creation (immutable afterward), status/completed_at are always
 * controller-set, never form fields.
 */
export interface LifecycleProcessFormPayload {
    employee_id: string;
    type: LifecycleProcessType | '';
    started_at: string;
    due_date: string;
}

/**
 * Allowlisted for the Edit form — employee_id/type are absent (fixed at
 * creation); status here is only ever a transition request (draft ->
 * in_progress -> completed/cancelled), validated server-side.
 */
export interface LifecycleProcessEditPayload {
    status: LifecycleProcessStatus | '';
    started_at: string;
    due_date: string;
}

/**
 * Allowlisted for task Create/Edit forms — process_id always comes from
 * the route, never the form; completed_at/completed_by are
 * controller-only (set via the dedicated complete/skip actions, not
 * this form).
 */
export interface LifecycleTaskFormPayload {
    title: string;
    description: string;
    assigned_to_user_id: string;
    due_date: string;
}
