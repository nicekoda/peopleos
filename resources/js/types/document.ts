// Mirrors EmployeeDocumentResource exactly (Checkpoint 8/19) — never
// storage_disk/storage_path/stored_filename, those are never sent by the
// backend in the first place. approved_by/approved_at are reserved
// fields (no approval workflow endpoint exists yet, Checkpoint 19 does
// not build one) but are included here since the API already returns
// them; the UI does not render them as actionable.
export type DocumentStatus = 'active' | 'archived' | 'rejected';

export interface EmployeeDocument {
    id: string;
    employee_id: string;
    document_category_id: string | null;
    title: string;
    description: string | null;
    original_filename: string;
    mime_type: string;
    file_extension: string;
    file_size: number;
    status: DocumentStatus;
    is_sensitive: boolean;
    issue_date: string | null;
    expiry_date: string | null;
    uploaded_by: number | null;
    approved_by: number | null;
    approved_at: string | null;
    created_at: string;
    updated_at: string;
}

// Mirrors DocumentCategoryResource exactly (Checkpoint 9).
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
    created_by: number | null;
    updated_by: number | null;
    created_at: string;
    updated_at: string;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}

/**
 * Fields the upload form is allowed to submit — allowlisted, not built by
 * spreading a broader object. tenant_id/employee_id/storage_path/
 * stored_filename/uploaded_by/approved_by/approved_at/status are never
 * fields here; the backend resolves/rejects all of those independently
 * (see StoreEmployeeDocumentRequest). `file` is sent as a real File object
 * inside a multipart FormData, not part of this JSON-shaped type.
 */
export interface EmployeeDocumentFormPayload {
    title: string;
    description: string;
    document_category_id: string;
    issue_date: string;
    expiry_date: string;
}
