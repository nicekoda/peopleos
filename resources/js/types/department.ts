// Mirrors DepartmentResource exactly (Checkpoint 32) — no
// tenant_id/created_by/updated_by/deleted_at.
export type DepartmentStatus = 'active' | 'inactive';

export interface Department {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    status: DepartmentStatus;
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
 * the Edit form, never Create (a new department always starts active
 * via the backend's own default).
 */
export interface DepartmentFormPayload {
    name: string;
    description: string;
    status: DepartmentStatus | '';
}
