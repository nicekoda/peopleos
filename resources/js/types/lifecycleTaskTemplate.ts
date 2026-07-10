// Mirrors LifecycleTaskTemplateResource exactly (Checkpoint 42) — no
// tenant_id/created_by/updated_by/deleted_at.
export type LifecycleProcessTypeValue = 'onboarding' | 'offboarding';

export interface LifecycleTaskTemplate {
    id: string;
    type: LifecycleProcessTypeValue;
    title: string;
    description: string | null;
    due_in_days: number | null;
    sort_order: number;
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
 * Allowlisted for the create/edit forms — tenant_id/created_by/
 * updated_by/deleted_at remain structurally absent.
 */
export interface LifecycleTaskTemplateFormPayload {
    type: LifecycleProcessTypeValue | '';
    title: string;
    description: string;
    due_in_days: string;
    sort_order: string;
}
