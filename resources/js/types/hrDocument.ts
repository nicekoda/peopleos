// Mirrors HrDocumentTemplateResource/HrGeneratedDocumentResource exactly
// (Checkpoint 34) — no tenant_id/created_by/updated_by/deleted_at.
export type HrDocumentTemplateStatus = 'active' | 'inactive';
export type HrGeneratedDocumentStatus = 'draft' | 'generated' | 'archived';
export type HrDocumentType =
    | 'employment_letter'
    | 'offer_letter'
    | 'confirmation_letter'
    | 'promotion_letter'
    | 'warning_letter'
    | 'exit_letter'
    | 'reference_letter'
    | 'contractor_engagement_letter';

export const HR_DOCUMENT_TYPE_LABELS: Record<HrDocumentType, string> = {
    employment_letter: 'Employment letter',
    offer_letter: 'Offer letter',
    confirmation_letter: 'Confirmation letter',
    promotion_letter: 'Promotion letter',
    warning_letter: 'Warning letter',
    exit_letter: 'Exit / offboarding letter',
    reference_letter: 'Reference letter',
    contractor_engagement_letter: 'Contractor engagement letter',
};

/**
 * The only tokens PlaceholderRenderer substitutes — anything else in
 * content_template is left completely unchanged (never executed, never
 * an error). Shown to template authors as a picker/reference, never
 * user-editable beyond inserting one of these exact strings.
 */
export const HR_DOCUMENT_ALLOWED_PLACEHOLDERS = [
    '{{employee.name}}',
    '{{employee.employee_number}}',
    '{{employee.email}}',
    '{{employee.department}}',
    '{{employee.position}}',
    '{{employee.location}}',
    '{{employee.employment_type}}',
    '{{employee.start_date}}',
    '{{tenant.name}}',
    '{{today}}',
] as const;

export interface HrDocumentTemplate {
    id: string;
    title: string;
    slug: string;
    description: string | null;
    document_type: HrDocumentType;
    content_template: string;
    status: HrDocumentTemplateStatus;
    created_at: string | null;
    updated_at: string | null;
}

export interface HrGeneratedDocument {
    id: string;
    employee_id: string;
    employee: { id: string; full_name: string; employee_number: string } | null;
    hr_document_template_id: string | null;
    employee_document_id: string | null;
    title: string;
    document_type: HrDocumentType;
    status: HrGeneratedDocumentStatus;
    rendered_content: string;
    generated_at: string | null;
    generated_by: number | null;
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
 * Allowlisted for the template Create/Edit forms — slug is
 * auto-generated from title server-side, never a form field. `status` is
 * only ever sent from the Edit form — Create never includes it, so a new
 * template always starts `active` via the backend's own default.
 */
export interface HrDocumentTemplateFormPayload {
    title: string;
    description: string;
    document_type: HrDocumentType | '';
    content_template: string;
    status: HrDocumentTemplateStatus | '';
}

/**
 * Generate form payload — employee_id/hr_document_template_id/title
 * only. tenant_id/status/rendered_content/generated_at/generated_by are
 * never fields here at all; the backend derives them entirely.
 */
export interface HrGeneratedDocumentFormPayload {
    employee_id: string;
    hr_document_template_id: string;
    title: string;
}
