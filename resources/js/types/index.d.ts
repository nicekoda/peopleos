/**
 * Shared Inertia page props (see App\Http\Middleware\HandleInertiaRequests).
 * This mirrors exactly what the backend shares — nothing more. See
 * docs/security.md for the full "what's shared, what never is" list.
 * Treat everything here as presentation data only: permission-aware UI
 * built from `auth.user.permissions` never replaces a backend check.
 */
export interface AuthUser {
    id: number;
    name: string;
    email: string;
    is_platform_admin: boolean;
    employee_id: string | null;
    permissions: string[];
}

/**
 * Checkpoint 47 — modules is a plain module_key => enabled map (missing
 * key defaults to enabled, mirroring the backend's own fail-open
 * fallback); branding exposes only the public logo URL and hex colors,
 * never logo_path/row IDs/actor IDs/timestamps — see
 * TenantModuleResource/TenantBrandingResource, which enforce the
 * identical safe shape for the API responses this mirrors.
 */
export interface SharedTenant {
    id: string;
    name: string;
    modules: Record<string, boolean> | null;
    branding: {
        logo_url: string | null;
        primary_color: string | null;
        secondary_color: string | null;
    };
}

export interface PageProps {
    auth: {
        user: AuthUser | null;
    };
    tenant: SharedTenant | null;
    errors: Record<string, string>;
    // Checkpoint 44 — a one-time, session-flashed success message (e.g.
    // "password reset link sent", "password reset — please sign in").
    // null on every request that didn't just redirect from one of those
    // actions.
    status?: string | null;
    [key: string]: unknown;
}
