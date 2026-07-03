<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Singleton tenant-context endpoint (Checkpoint 22) — no {tenant} route
 * parameter anywhere, on purpose. There is no way to request a
 * different tenant's record through this controller: both actions
 * operate exclusively on app(Tenant::class), the tenant tenant.matches
 * already confirmed the caller belongs to. Never accepts a tenant ID
 * from the URL, request body, query string, or any other input — same
 * "no tenant switching" guarantee as /me/employee (Checkpoint 11) for
 * employee context. See docs/security.md.
 */
class TenantController extends Controller
{
    /**
     * permission:tenant.view middleware already blocks Platform Super
     * Admins (a platform role can never hold a tenant-scoped
     * permission) — this explicit check is defense in depth and matters
     * concretely: app(Tenant::class) is never bound for a platform
     * admin (no tenant resolved), so resolving it here without this
     * guard would throw an unhandled exception (raw 500) instead of a
     * clean 403.
     */
    public function show(Request $request): TenantResource
    {
        abort_if($request->user()->is_platform_admin, 403, 'Tenant profile is tenant-scoped only.');

        return new TenantResource(app(Tenant::class));
    }

    public function update(UpdateTenantRequest $request): TenantResource
    {
        abort_if($request->user()->is_platform_admin, 403, 'Tenant profile is tenant-scoped only.');

        $tenant = app(Tenant::class);
        $oldName = $tenant->name;

        $tenant->fill($request->validated());
        $tenant->save();

        if ($tenant->wasChanged('name')) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'tenant.updated',
                module: 'tenant',
                tenantId: $tenant->id,
                auditableType: Tenant::class,
                auditableId: $tenant->id,
                description: "Tenant name changed from '{$oldName}' to '{$tenant->name}'.",
                oldValues: ['name' => $oldName],
                newValues: ['name' => $tenant->name],
                metadata: [
                    'old_name' => $oldName,
                    'new_name' => $tenant->name,
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $request->user()->id,
                ],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new TenantResource($tenant);
    }
}
