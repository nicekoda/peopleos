<?php

namespace App\Http\Middleware;

use App\Enums\TenantModule;
use App\Services\TenantModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checkpoint 47 — Module Registry & Branding Foundation.
 *
 * Usage: ->middleware('module:recruitment') — the {key} parameter is
 * always a route-config-time literal (never derived from request
 * input), so TenantModule::from() throwing on a typo is a deliberate,
 * fail-loud developer error, not something this middleware validates
 * defensively — unlike TenantModuleController's PATCH endpoint, where
 * the module key genuinely comes from the request and gets a clean 422
 * instead.
 *
 * Runs after `auth`/`tenant.matches`, before `permission:` — a
 * disabled module blocks access before any permission is even checked,
 * per your approved middleware order. A Platform Super Admin never
 * reaches this middleware in the first place on a tenant subdomain:
 * `tenant.matches` already rejects them (see
 * EnsureTenantMatchesAuthenticatedUser — `resolvedTenant === null` is
 * required for a platform-admin session to pass), so this middleware
 * is never the thing standing between a platform admin and a disabled
 * module — verified directly, not assumed (see
 * TenantModuleApiTest::test_platform_super_admin_cannot_reach_tenant_module_routes_at_all()).
 */
class EnsureModuleEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $key): Response
    {
        $module = TenantModule::from($key);

        if (! app(TenantModuleService::class)->isEnabled($module)) {
            abort(response()->json([
                'message' => 'This module is not enabled for your organisation.',
                'reason' => 'module_disabled',
            ], 403));
        }

        return $next($request);
    }
}
