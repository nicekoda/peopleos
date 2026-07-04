// Mirrors PermissionResource exactly (Checkpoint 23) — a global,
// non-tenant-owned permission definition. No role/user pivot data.
export interface Permission {
    id: number;
    key: string;
    category: string;
    description: string | null;
}
