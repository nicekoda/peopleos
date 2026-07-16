// Mirrors TenantBrandingResource exactly (Checkpoint 47) — never
// logo_path, row ID, or actor fields.
export interface TenantBrandingState {
    logo_url: string | null;
    logo_original_filename: string | null;
    primary_color: string | null;
    secondary_color: string | null;
}
