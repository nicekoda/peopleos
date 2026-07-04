// Mirrors AuditLogResource exactly (Checkpoint 24) — no ip_address,
// user_agent, or raw sensitive values; metadata/old_values/new_values
// are already sanitized server-side (AuditValueSanitizer) before this
// shape ever reaches the frontend.
export type AuditLogSeverity = 'info' | 'warning' | 'critical';

export interface AuditLog {
    id: number;
    tenant_id: string;
    actor_user_id: number | null;
    actor_type: string | null;
    action: string;
    module: string;
    auditable_type: string | null;
    auditable_id: string | null;
    target_user_id: number | null;
    description: string | null;
    severity: AuditLogSeverity;
    created_at: string;
    metadata: Record<string, unknown> | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
