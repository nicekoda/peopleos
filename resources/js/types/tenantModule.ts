// Mirrors TenantModuleResource exactly (Checkpoint 47) — no row IDs,
// actor IDs, or timestamps, even for a tenant.modules.view-only caller.
export interface TenantModuleState {
    module_key: string;
    label: string;
    description: string;
    enabled: boolean;
    toggleable: boolean;
    related_modules: string[];
    warning_count: number | null;
}
