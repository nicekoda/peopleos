<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirms the authenticated user actually belongs to the tenant this
 * request resolved to. Must run after 'auth' (needs $request->user()) and
 * before any permission check or tenant-scoped query/model-binding.
 *
 * Why this exists: SESSION_DOMAIN=.peopleos.test shares session cookies
 * across every subdomain (deliberate, for subdomain-based tenancy), so a
 * logged-in user's browser sends valid session cookies to *any* tenant's
 * subdomain automatically. Without this check, an authenticated tenant-A
 * user visiting tenant-B's subdomain would pass 'auth' (they have a valid
 * session) and permission checks (hasPermission() only checks the user's
 * own tenant's active status, not whether it matches the current
 * request), and BelongsToTenant's global scope would then filter by
 * tenant B (the request's resolved tenant) — leaking tenant B's data to a
 * tenant A user. Found and fixed in Checkpoint 7.
 */
class EnsureTenantMatchesAuthenticatedUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $resolvedTenant = app()->bound(Tenant::class) ? app(Tenant::class) : null;

        $matches = $user->is_platform_admin
            ? $resolvedTenant === null
            : $resolvedTenant !== null && $resolvedTenant->id === $user->tenant_id;

        if (! $matches) {
            AuditLogger::logFor(
                actor: $user,
                action: 'tenant.mismatch_blocked',
                module: 'security',
                tenantId: $resolvedTenant?->id,
                targetUserId: $user->id,
                description: "User #{$user->id} (tenant {$user->tenant_id}) blocked: request resolved to a different tenant context.",
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                severity: 'critical',
            );

            abort(403, 'This session is not valid for the requested tenant.');
        }

        return $next($request);
    }
}
