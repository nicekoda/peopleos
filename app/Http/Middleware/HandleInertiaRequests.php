<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Services\TenantModuleService;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * The single place shared frontend props are assembled (Checkpoint 16).
 * Everything shared here is presentation data only — permission-aware
 * UI built from it (PermissionGate/useCan) never replaces a backend
 * check. See docs/security.md for the full "what's shared, what never
 * is" list.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // app()->bound(Tenant::class) is only true when ResolveTenant
        // actually bound one for this request (i.e. a real tenant
        // subdomain) — a Platform Super Admin on the base domain
        // correctly gets tenant: null here, never a fabricated/forced
        // tenant context. There is no tenant-switching input anywhere
        // in this payload.
        $tenant = app()->bound(Tenant::class) ? app(Tenant::class) : null;

        // Checkpoint 47 — module/branding state only ever computed when a
        // tenant is actually resolved (never for a Platform Super Admin
        // on the base domain). Deliberately only the safe subset: an
        // enabled-map keyed by module_key (no row IDs/actor IDs/
        // timestamps), and branding's public logo URL + hex colors only
        // (never logo_path or any other internal field) — see
        // TenantModuleResource/TenantBrandingResource, which enforce the
        // identical safe shape for the API responses this mirrors.
        $modules = $tenant ? app(TenantModuleService::class)->enabledMap() : null;
        $branding = $tenant ? TenantBranding::query()->where('tenant_id', $tenant->id)->first() : null;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_platform_admin' => $user->is_platform_admin,
                    'employee_id' => $user->employee?->id,
                    'permissions' => $user->permissionKeys(),
                ] : null,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'modules' => $modules,
                'branding' => [
                    'logo_url' => $branding?->logoUrl(),
                    'primary_color' => $branding?->primary_color,
                    'secondary_color' => $branding?->secondary_color,
                ],
            ] : null,
            // Checkpoint 44 — the first page in this app that redirects
            // back to itself (or elsewhere) with a one-time success
            // message, rather than either staying on an API-driven page
            // with local component state, or redirecting straight to a
            // new authenticated page. Session-flashed, read once
            // (Laravel clears it after the next request automatically).
            // Only added to the props array at all when a flash actually
            // exists — every "page props contain only IDs" test across
            // this app (Employees, Departments, Leave, Policies, and
            // more) asserts an exact key list, and every one of those
            // pages is reached via a plain GET with no flash, so this
            // must never appear as a spurious null-valued key on them.
            ...($request->session()->has('status') ? ['status' => $request->session()->get('status')] : []),
        ];
    }
}
