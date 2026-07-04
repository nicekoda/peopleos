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

        foreach (['settings/access/users', "settings/access/users/{$target->id}", 'settings/access/roles'] as $path) {
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
}
