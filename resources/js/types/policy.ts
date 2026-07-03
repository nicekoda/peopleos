// Mirrors PolicyResource exactly (Checkpoint 10/20). owner_user_id is
// returned by the API but has no safe lookup UI yet (no /api/v1/users
// listing endpoint exists) — see docs/security.md — so it's rendered
// read-only where present, never editable via a form field.
export type PolicyStatus = 'draft' | 'published' | 'archived';

export interface Policy {
    id: string;
    title: string;
    slug: string;
    code: string | null;
    description: string | null;
    category: string | null;
    owner_user_id: number | null;
    status: PolicyStatus;
    current_version_id: string | null;
    effective_date: string | null;
    review_date: string | null;
    created_by: number | null;
    updated_by: number | null;
    created_at: string | null;
    updated_at: string | null;
}

// Mirrors PolicyVersionResource exactly.
export interface PolicyVersion {
    id: string;
    policy_id: string;
    version_number: number;
    title: string;
    summary: string | null;
    content: string | null;
    employee_document_id: string | null;
    status: PolicyStatus;
    published_by: number | null;
    published_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

// Mirrors PolicyAcknowledgementResource exactly. ip_address/user_agent
// are returned by the API but deliberately never rendered in this UI —
// unnecessary technical/personal data for this checkpoint's screens.
export type AcknowledgementStatus = 'pending' | 'acknowledged' | 'overdue' | 'waived';
export type AcknowledgementMethod = 'web' | 'admin_recorded';

export interface PolicyAcknowledgement {
    id: string;
    policy_id: string;
    policy_version_id: string;
    employee_id: string;
    assigned_by: number | null;
    assigned_at: string | null;
    due_date: string | null;
    acknowledged_at: string | null;
    acknowledgement_status: AcknowledgementStatus;
    acknowledgement_method: AcknowledgementMethod | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
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
 * Allowlisted fields for Create/Edit — tenant_id/status(unless
 * archiving, not offered here)/current_version_id/created_by/updated_by/
 * published_by/audit fields are never part of this form. owner_user_id
 * is omitted entirely (see the Policy interface's comment above).
 */
export interface PolicyFormPayload {
    title: string;
    code: string;
    description: string;
    category: string;
    effective_date: string;
    review_date: string;
}

/**
 * version_number is never sent — the backend computes it
 * (max(version_number) + 1). employee_document_id is never sent — no
 * safe document picker exists yet (see docs/security.md).
 */
export interface PolicyVersionFormPayload {
    title: string;
    summary: string;
    content: string;
}

/**
 * Matches AssignPolicyRequest exactly — tenant_id/policy_id/assigned_by/
 * assigned_at/acknowledgement_status are never fields here.
 */
export interface PolicyAssignFormPayload {
    employee_ids: string[];
    due_date: string;
}
