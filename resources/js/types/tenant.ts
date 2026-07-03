// Mirrors TenantResource exactly (Checkpoint 22) — id/name/subdomain/
// status/dates only. No billing, security, or system-flag fields exist
// to expose.
export interface Tenant {
    id: string;
    name: string;
    subdomain: string;
    status: string;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * Allowlisted for the edit form — `name` only. subdomain/status/
 * tenant_id/billing/security/system-flag fields are structurally
 * absent, matching what UpdateTenantRequest actually accepts.
 */
export interface TenantFormPayload {
    name: string;
}
