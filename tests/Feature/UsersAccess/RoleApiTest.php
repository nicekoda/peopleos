<?php

namespace Tests\Feature\UsersAccess;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 23 — GET /api/v1/roles. Role does NOT use BelongsToTenant
 * (see docs/security.md) — every tenant/scope isolation test here is
 * testing the primary defense, not a backstop.
 */
class RoleApiTest extends TestCase
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
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    public function test_guest_cannot_access_role_list(): void
    {
        $tenant = Tenant::factory()->create();

        $this->getJson($this->url($tenant, 'roles'))->assertUnauthorized();
    }

    public function test_user_without_roles_view_cannot_access_role_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'roles'))->assertForbidden();
    }

    public function test_user_with_roles_view_can_access_role_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        Role::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'roles'));

        $response->assertOk();
        // The throwaway role created for $user themselves + 2 more = 3.
        $this->assertCount(3, $response->json('data'));
    }

    public function test_tenant_a_cannot_view_tenant_b_roles(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'roles.view');
        Role::factory()->count(3)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'roles'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_platform_roles_are_not_reachable_through_tenant_role_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        Role::factory()->platform()->create(['name' => 'Platform Super Admin', 'slug' => 'platform-super-admin-'.uniqid()]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'roles'));

        $response->assertOk();
        $this->assertNotContains('Platform Super Admin', collect($response->json('data'))->pluck('name'));
    }

    public function test_role_api_does_not_expose_raw_pivot_internals(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'roles'));
        $body = json_encode($response->json());

        foreach (['role_permission', 'user_role', 'pivot'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Role API response unexpectedly contains '{$internalKey}'.");
        }
    }

    public function test_role_permission_count_is_accurate(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::query()->firstOrCreate(['key' => 'leave.view'], ['category' => 'leave', 'is_platform_permission' => false]);
        $role->givePermissionTo($permission);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'roles'));
        $roleData = collect($response->json('data'))->firstWhere('id', $role->id);

        $this->assertSame(1, $roleData['permission_count']);
    }

    public function test_platform_super_admin_gets_safe_behaviour_on_role_api(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($platformAdmin)->getJson('http://'.config('tenancy.base_domain').'/api/v1/roles');

        $response->assertForbidden();
    }
}
