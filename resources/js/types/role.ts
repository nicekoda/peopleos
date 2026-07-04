import { Permission } from '@/types/permission';

// Mirrors RoleResource exactly (Checkpoint 23, extended Checkpoint 28)
// — no raw role_permission pivot rows. `permissions` is only ever
// populated by the API when the controller eager-loaded it (show(),
// and the permission assign/remove responses) — index() never returns
// it, so a list-page consumer of this type simply won't have it.
export interface Role {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    is_platform_role: boolean;
    is_system_role: boolean;
    permission_count: number;
    user_count: number | null;
    permissions?: Permission[];
    created_at: string | null;
    updated_at: string | null;
}

// Allowlisted create/update payload — name/description only. Never
// tenant_id/is_system_role/is_platform_role/slug/type/scope/system or
// platform flags; the backend structurally excludes all of those from
// StoreRoleRequest/UpdateRoleRequest regardless of what a form sends.
export interface RoleFormPayload {
    name: string;
    description: string | null;
}
