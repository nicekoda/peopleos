<?php

namespace Tests\Feature\UsersAccess;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 23 — /settings/access/users, /settings/access/users/{user},
 * /settings/access/roles. Same shape as every other module UI test —
 * permission gating, guest redirects, tenant isolation, and IDs-only
 * props for the detail page.
 */
class UsersAccessUiTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(Tenant $tenant, string ...$permissionKeys): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['category' => explode('.', $key)[0], 'is_platform_permission' => false],
            );
            $role->givePermissionTo($permission);
        }

        $user->assignRole($role);

        return $user;
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/'.$path;
    }

    public function test_guest_cannot_access_users_access_ui(): void
    {
        $tenant = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['settings/access/users', 'settings/access/users/create', "settings/access/users/{$target->id}", 'settings/access/roles'] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_users_view_cannot_access_user_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/access/users'))->assertForbidden();
    }

    public function test_user_with_users_view_can_access_user_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/access/users'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AccessUsers'));
    }

    /**
     * Checkpoint 43 — /settings/access/users/create. Same guest/
     * permission-gating shape as every other create page in this app;
     * gated on users.create, not users.view (matching the API route).
     */
    public function test_guest_cannot_access_create_user_page(): void
    {
        $tenant = Tenant::factory()->create();

        $this->get($this->url($tenant, 'settings/access/users/create'))->assertRedirect(route('login'));
    }

    public function test_user_without_users_create_cannot_access_create_user_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');

        $this->actingAs($user)->get($this->url($tenant, 'settings/access/users/create'))->assertForbidden();
    }

    public function test_user_with_users_create_can_access_create_user_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.create');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/access/users/create'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AccessUserCreate'));
    }

    public function test_user_without_roles_view_cannot_access_role_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, 'settings/access/roles'))->assertForbidden();
    }

    public function test_user_with_roles_view_can_access_role_list_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');

        $response = $this->actingAs($user)->get($this->url($tenant, 'settings/access/roles'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AccessRoles'));
    }

    public function test_user_detail_page_props_contain_only_user_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Confidential Target Name']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/access/users/{$target->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AccessUserShow')->where('userId', $target->id));

        $page = $response->viewData('page');
        $this->assertSame(['userId'], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
        $this->assertStringNotContainsString('Confidential Target Name', json_encode($page['props']));
    }

    public function test_cross_tenant_user_id_returns_404_on_detail_page(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'users.view');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/access/users/{$userB->id}"))->assertNotFound();
    }

    public function test_platform_admin_id_returns_404_on_detail_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'users.view');
        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($user)->get($this->url($tenant, "settings/access/users/{$platformAdmin->id}"))->assertNotFound();
    }

    public function test_employee_cannot_access_users_access_ui_by_default(): void
    {
        $tenant = Tenant::factory()->create();
        // Mirrors the seeded Employee role's actual permission set —
        // no users.*/roles.* keys — see RoleSeeder.
        $employeeLikeUser = $this->userWithPermissions($tenant, 'dashboard.view', 'leave.view');

        $this->actingAs($employeeLikeUser)->get($this->url($tenant, 'settings/access/users'))->assertForbidden();
        $this->actingAs($employeeLikeUser)->get($this->url($tenant, 'settings/access/roles'))->assertForbidden();
    }

    /**
     * Checkpoint 28 — role create/show/edit page routes. Same guest/
     * permission/tenant-isolation shape as every existing page in this
     * file, extended to the three new routes.
     */
    public function test_guest_cannot_access_role_management_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['settings/access/roles/create', "settings/access/roles/{$role->id}", "settings/access/roles/{$role->id}/edit"] as $path) {
            $this->get($this->url($tenant, $path))->assertRedirect(route('login'));
        }
    }

    public function test_user_without_roles_create_cannot_access_role_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');

        $this->actingAs($user)->get($this->url($tenant, 'settings/access/roles/create'))->assertForbidden();
    }

    public function test_user_with_roles_create_can_access_role_create_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.create');

        $this->actingAs($user)->get($this->url($tenant, 'settings/access/roles/create'))->assertOk();
    }

    public function test_role_detail_page_props_contain_only_role_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Confidential Role Name']);

        $response = $this->actingAs($user)->get($this->url($tenant, "settings/access/roles/{$role->id}"));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Settings/AccessRoleShow')->where('roleId', $role->id));

        $page = $response->viewData('page');
        $this->assertSame(['roleId'], array_keys(array_diff_key($page['props'], ['errors' => 1, 'auth' => 1, 'tenant' => 1])));
        $this->assertStringNotContainsString('Confidential Role Name', json_encode($page['props']));
    }

    public function test_cross_tenant_role_id_returns_404_on_role_pages(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'roles.view', 'roles.update');
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->get($this->url($tenantA, "settings/access/roles/{$roleB->id}"))->assertNotFound();
        $this->actingAs($userA)->get($this->url($tenantA, "settings/access/roles/{$roleB->id}/edit"))->assertNotFound();
    }

    public function test_platform_role_id_returns_404_on_role_pages(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view', 'roles.update');
        $platformRole = Role::factory()->platform()->create();

        $this->actingAs($user)->get($this->url($tenant, "settings/access/roles/{$platformRole->id}"))->assertNotFound();
        $this->actingAs($user)->get($this->url($tenant, "settings/access/roles/{$platformRole->id}/edit"))->assertNotFound();
    }

    public function test_user_without_roles_update_cannot_access_role_edit_page(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "settings/access/roles/{$role->id}/edit"))->assertForbidden();
    }

    /**
     * The edit *page* route stays reachable for a system role (it's
     * gated on roles.update, not on is_system_role) — the page itself
     * renders the "System roles are protected" message client-side once
     * it fetches the real record, and any submit attempt is
     * independently rejected (403) by the API regardless. This test
     * only confirms the route itself doesn't 404/403 for a system role
     * — see RolePermissionApiTest / RoleApiTest for the actual
     * edit-rejection coverage.
     */
    public function test_role_edit_page_route_is_reachable_for_a_system_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view', 'roles.update');
        $systemRole = Role::factory()->system()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->get($this->url($tenant, "settings/access/roles/{$systemRole->id}/edit"))->assertOk();
    }
}
