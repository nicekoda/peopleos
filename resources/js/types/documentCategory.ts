// Mirrors DocumentCategoryResource exactly (Checkpoint 25) — no
// tenant_id/created_by/updated_by/deleted_at.
export type DocumentCategoryStatus = 'active' | 'inactive';
export type DocumentAppliesTo = 'employee' | 'tenant' | 'policy' | 'candidate' | 'general';

export interface DocumentCategory {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    applies_to: DocumentAppliesTo;
    is_sensitive: boolean;
    is_required: boolean;
    requires_expiry_date: boolean;
    status: DocumentCategoryStatus;
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
 * Allowlisted for the create/edit forms (Refinement 2) — `applies_to`
 * is deliberately omitted (defaults to `employee` server-side, matching
 * every category in real use today); `slug` is auto-generated from
 * `name` server-side, never a form field. `status` is only ever sent
 * from the Edit form — the Create form never includes it, so a new
 * category always starts `active` via the backend's own default.
 */
export interface DocumentCategoryFormPayload {
    name: string;
    description: string;
    is_sensitive: boolean;
    is_required: boolean;
    requires_expiry_date: boolean;
    status: DocumentCategoryStatus | '';
}
