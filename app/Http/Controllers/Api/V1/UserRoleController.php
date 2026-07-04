<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AssignUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantAdminProtectionService;
use Illuminate\Http\Request;

/**
 * Role assignment is security-sensitive (Checkpoint 23, Refinement 5) —
 * every safeguard here is layered, not single-point:
 *   1. AssignUserRoleRequest validates role_id against a tenant- and
 *      scope-restricted Rule::exists() (422 on violation).
 *   2. User::assignRole()/removeRole() independently re-check
 *      platform-vs-tenant scope and tenant ownership before writing
 *      anything (RuntimeException — should be unreachable given layer
 *      1, kept as a backstop regardless).
 *   3. TenantAdminProtectionService blocks removing the tenant's last
 *      Tenant Admin role (409).
 *   4. Both actions write audit logs (inside assignRole()/removeRole()
 *      themselves, Checkpoint 4/5).
 */
class UserRoleController extends Controller
{
    public function store(AssignUserRoleRequest $request, User $user): UserResource
    {
        $this->ensureUserBelongsToCurrentTenant($user);

        $role = Role::query()->findOrFail($request->validated('role_id'));

        $user->assignRole($role, $request->user());

        $user->loadMissing(['roles', 'employee']);

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user, Role $role): UserResource
    {
        $this->ensureUserBelongsToCurrentTenant($user);
        $this->ensureRoleBelongsToCurrentTenant($role);

        if ($role->slug === TenantAdminProtectionService::TENANT_ADMIN_SLUG) {
            abort_if(
                app(TenantAdminProtectionService::class)->wouldLeaveTenantWithoutAdmin($user),
                409,
                'Cannot remove the last Tenant Admin role in this tenant.',
            );
        }

        $user->removeRole($role, $request->user());

        $user->loadMissing(['roles', 'employee']);

        return new UserResource($user);
    }

    protected function ensureUserBelongsToCurrentTenant(User $user): void
    {
        abort_if($user->is_platform_admin, 404);
        abort_unless($user->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureRoleBelongsToCurrentTenant(Role $role): void
    {
        abort_if($role->is_platform_role, 404);
        abort_unless($role->tenant_id === app(Tenant::class)->id, 404);
    }
}
