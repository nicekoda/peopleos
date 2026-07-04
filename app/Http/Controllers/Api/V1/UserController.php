<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\TenantAdminProtectionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * User and Role (Checkpoint 23) are the first tenant-scoped models in
 * this app that do NOT use BelongsToTenant — login must work before a
 * tenant is known, and Platform Super Admins need cross-tenant
 * visibility (see docs/security.md). That means the manual
 * where('tenant_id', ...) filter below is not defense-in-depth on top
 * of a global scope — it is the *only* tenant boundary here, and every
 * query in this controller applies it explicitly (Refinement 1).
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->where('tenant_id', app(Tenant::class)->id)
            ->where('is_platform_admin', false)
            ->with(['roles', 'employee'])
            ->orderBy('name')
            ->paginate();

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): UserResource
    {
        $this->ensureBelongsToCurrentTenant($user);

        $user->loadMissing(['roles', 'employee']);

        return new UserResource($user);
    }

    /**
     * Status-only (Refinement 3) — UpdateUserStatusRequest validates
     * exactly `status`; nothing else on the User model can be changed
     * through this action. Refinement 4: blocked if this would leave
     * the tenant with zero Tenant Admins, regardless of who is
     * performing the change.
     */
    public function update(UpdateUserStatusRequest $request, User $user): UserResource
    {
        $this->ensureBelongsToCurrentTenant($user);

        $newStatus = $request->validated('status');
        $oldStatus = $user->status;

        if ($newStatus !== User::STATUS_ACTIVE && $oldStatus === User::STATUS_ACTIVE) {
            abort_if(
                app(TenantAdminProtectionService::class)->wouldLeaveTenantWithoutAdmin($user),
                409,
                'This would leave the tenant with no active Tenant Admin.',
            );
        }

        if ($newStatus !== $oldStatus) {
            $user->update(['status' => $newStatus]);

            AuditLogger::logFor(
                actor: $request->user(),
                action: 'user.status_updated',
                module: 'users',
                tenantId: $user->tenant_id,
                auditableType: User::class,
                auditableId: (string) $user->id,
                targetUserId: $user->id,
                description: "User #{$user->id} status changed from '{$oldStatus}' to '{$newStatus}'.",
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        $user->loadMissing(['roles', 'employee']);

        return new UserResource($user);
    }

    /**
     * Defense in depth beyond the manual tenant filter above — same
     * pattern as every other controller in this app, applied here as
     * the primary (not secondary) safeguard since no global scope
     * exists for User. Also blocks Platform Super Admin records
     * explicitly (Refinement 2) — their tenant_id is always null and
     * could never match the current tenant anyway, but this makes the
     * rule explicit rather than incidental.
     */
    protected function ensureBelongsToCurrentTenant(User $user): void
    {
        abort_if($user->is_platform_admin, 404);
        abort_unless($user->tenant_id === app(Tenant::class)->id, 404);
    }
}
