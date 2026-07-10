<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 23), same pattern as every other
 * module — no user/role data is ever passed as a page prop (except an
 * ID-only prop for detail/edit pages). Each page fetches the actual
 * record(s) client-side from the new /api/v1/users|roles|permissions
 * endpoints. Because User/Role do not use BelongsToTenant (see
 * docs/security.md), show()/roleShow()/roleEdit() add the same explicit
 * tenant + platform check the API layer relies on — this is the
 * primary tenant boundary here, not defense-in-depth on top of a scope.
 */
class UsersAccessUiController extends Controller
{
    public function users(): Response
    {
        return Inertia::render('Settings/AccessUsers');
    }

    public function show(User $user): Response
    {
        $this->ensureUserBelongsToCurrentTenant($user);

        return Inertia::render('Settings/AccessUserShow', ['userId' => $user->id]);
    }

    /**
     * Checkpoint 43. Same "backend is the authority" shape as
     * roleCreate() — role/employee data is fetched client-side from the
     * existing /api/v1/roles and /api/v1/employees endpoints, never
     * passed as a page prop. An optional ?employeeId= query param
     * (from an Employee detail page's "Create user account" link) only
     * pre-fills the form client-side; nothing about it is trusted here.
     */
    public function userCreate(): Response
    {
        return Inertia::render('Settings/AccessUserCreate');
    }

    public function roles(): Response
    {
        return Inertia::render('Settings/AccessRoles');
    }

    /**
     * Checkpoint 28. The page itself doesn't know or care whether the
     * role is a system role — that's decided from the real API response
     * (is_system_role) once the page fetches the record, same
     * "backend is the authority, frontend only renders what it's told"
     * rule as everywhere else in this app.
     */
    public function roleCreate(): Response
    {
        return Inertia::render('Settings/AccessRoleCreate');
    }

    public function roleShow(Role $role): Response
    {
        $this->ensureRoleBelongsToCurrentTenant($role);

        return Inertia::render('Settings/AccessRoleShow', ['roleId' => $role->id]);
    }

    /**
     * Reachable by URL even for a system role — the page itself renders
     * a safe "protected" message once its client-side fetch reveals
     * is_system_role, and any submit attempt is independently rejected
     * (403) by RoleController::update() regardless. Not blocked at the
     * route level, since the role IS visible/viewable, just not
     * editable — same 404-vs-403 distinction as the API layer.
     */
    public function roleEdit(Role $role): Response
    {
        $this->ensureRoleBelongsToCurrentTenant($role);

        return Inertia::render('Settings/AccessRoleEdit', ['roleId' => $role->id]);
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
