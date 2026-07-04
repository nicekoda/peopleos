<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\AssignRolePermissionRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Checkpoint 28 — role-level permission assignment, gated by
 * `permissions.assign` (the existing, previously-unused permission key
 * approved for this purpose; `roles.assign_permission` does not exist
 * in this app's catalog). Every safeguard here is layered, not
 * single-point:
 *   1. AssignRolePermissionRequest validates permission_id against a
 *      tenant-safe (is_platform_permission: false) Rule::exists() (422
 *      on violation) for store(); destroy() re-checks the same fact
 *      directly since there's no request body to validate against.
 *   2. ensureRoleBelongsToCurrentTenant() rejects a platform or
 *      cross-tenant role (404) before anything else runs.
 *   3. ensureNotSystemRole() rejects a built-in role (403) — the
 *      "safer MVP" lockdown that makes a Tenant-Admin-lockout scenario
 *      structurally impossible this checkpoint, since the one role
 *      that could matter for lockout (Tenant Admin) is always a system
 *      role and therefore can never reach this controller's mutation
 *      logic at all.
 *   4. Role::assignPermission()/removePermission() independently
 *      re-assert the platform-vs-tenant scope match and write the
 *      audit log (Checkpoint 28).
 */
class RolePermissionController extends Controller
{
    public function store(AssignRolePermissionRequest $request, Role $role): RoleResource
    {
        $this->ensureRoleBelongsToCurrentTenant($role);
        $this->ensureNotSystemRole($role);

        $permission = Permission::query()->findOrFail($request->validated('permission_id'));

        $role->assignPermission($permission, $request->user());

        $role->loadCount(['permissions', 'users']);
        $role->load(['permissions' => fn ($query) => $query->orderBy('category')->orderBy('key')]);

        return new RoleResource($role);
    }

    public function destroy(Request $request, Role $role, Permission $permission): RoleResource
    {
        $this->ensureRoleBelongsToCurrentTenant($role);
        $this->ensureNotSystemRole($role);

        abort_if($permission->is_platform_permission, 404);

        $role->removePermission($permission, $request->user());

        $role->loadCount(['permissions', 'users']);
        $role->load(['permissions' => fn ($query) => $query->orderBy('category')->orderBy('key')]);

        return new RoleResource($role);
    }

    protected function ensureRoleBelongsToCurrentTenant(Role $role): void
    {
        abort_if($role->is_platform_role, 404);
        abort_unless($role->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureNotSystemRole(Role $role): void
    {
        abort_if($role->is_system_role, 403, 'System roles are protected and cannot be edited in this checkpoint.');
    }
}
