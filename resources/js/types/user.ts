// Mirrors UserResource exactly (Checkpoint 23) — no password,
// remember_token, tokens, last_login_ip, or raw role-pivot records.
export type UserStatus = 'active' | 'inactive' | 'suspended';

export interface UserRoleSummary {
    id: number;
    name: string;
    slug: string;
}

export interface UserLinkedEmployee {
    id: string;
    full_name: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    status: UserStatus;
    is_platform_admin: boolean;
    roles: UserRoleSummary[];
    linked_employee: UserLinkedEmployee | null;
    last_login_at: string | null;
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
