// Mirrors PositionResource exactly (Checkpoint 32) — no
// tenant_id/created_by/updated_by/deleted_at.
export type PositionStatus = 'active' | 'inactive';

export interface Position {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    status: PositionStatus;
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
 * Allowlisted for the create/edit forms — slug is never a form field
 * (always server-generated from name); status is only ever sent from
 * the Edit form, never Create.
 */
export interface PositionFormPayload {
    name: string;
    description: string;
    status: PositionStatus | '';
}
