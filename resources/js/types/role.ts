// Mirrors RoleResource exactly (Checkpoint 23) — no raw
// role_permission pivot rows, only a computed permission_count.
export interface Role {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_platform_role: boolean;
    permission_count: number;
    created_at: string | null;
    updated_at: string | null;
}
