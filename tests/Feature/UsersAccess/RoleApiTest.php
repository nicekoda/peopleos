<?php

namespace Tests\Feature\UsersAccess;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TenantSeeder;
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

    // ---- Checkpoint 28: show() ----

    public function test_user_without_roles_view_cannot_access_role_detail(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, "roles/{$role->id}"))->assertForbidden();
    }

    public function test_user_with_roles_view_can_access_role_detail(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::query()->firstOrCreate(['key' => 'leave.view'], ['category' => 'leave', 'is_platform_permission' => false]);
        $role->givePermissionTo($permission);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "roles/{$role->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.id', $role->id);
        $this->assertContains('leave.view', collect($response->json('data.permissions'))->pluck('key'));
    }

    public function test_tenant_a_cannot_view_tenant_b_role_detail(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'roles.view');
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($userA)->getJson($this->url($tenantA, "roles/{$roleB->id}"))->assertNotFound();
    }

    public function test_platform_role_cannot_be_viewed_by_guessed_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $platformRole = Role::factory()->platform()->create();

        $this->actingAs($user)->getJson($this->url($tenant, "roles/{$platformRole->id}"))->assertNotFound();
    }

    public function test_role_detail_does_not_expose_raw_pivot_internals(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::query()->firstOrCreate(['key' => 'leave.view'], ['category' => 'leave', 'is_platform_permission' => false]);
        $role->givePermissionTo($permission);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "roles/{$role->id}"));
        $body = json_encode($response->json());

        foreach (['role_permission', 'user_role', 'pivot'] as $internalKey) {
            $this->assertStringNotContainsString($internalKey, $body, "Role detail response unexpectedly contains '{$internalKey}'.");
        }
    }

    // ---- Checkpoint 28: store() ----

    public function test_user_without_roles_create_cannot_create_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');

        $this->actingAs($user)->postJson($this->url($tenant, 'roles'), ['name' => 'Custom Role'])->assertForbidden();
    }

    public function test_user_with_roles_create_can_create_custom_tenant_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'roles'), [
            'name' => 'Regional Coordinator',
            'description' => 'Coordinates across regions.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Regional Coordinator');
        $response->assertJsonPath('data.is_system_role', false);
        $response->assertJsonPath('data.is_platform_role', false);

        $this->assertDatabaseHas('roles', [
            'name' => 'Regional Coordinator',
            'tenant_id' => $tenant->id,
            'is_system_role' => false,
            'is_platform_role' => false,
        ]);
    }

    public function test_created_role_ignores_tenant_id_slug_and_system_flags_from_request(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'roles'), [
            'name' => 'Forged Role',
            'tenant_id' => $otherTenant->id,
            'slug' => 'forged-slug',
            'is_system_role' => true,
            'is_platform_role' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'forged-role');
        $response->assertJsonPath('data.is_system_role', false);
        $response->assertJsonPath('data.is_platform_role', false);
        $this->assertDatabaseHas('roles', ['name' => 'Forged Role', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('roles', ['name' => 'Forged Role', 'tenant_id' => $otherTenant->id]);
    }

    // ---- Checkpoint 28: update() ----

    public function test_user_without_roles_update_cannot_update_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "roles/{$role->id}"), ['name' => 'New Name'])->assertForbidden();
    }

    public function test_user_with_roles_update_can_edit_custom_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.update');
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "roles/{$role->id}"), [
            'name' => 'New Name',
            'description' => 'Updated description.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'New Name']);
    }

    public function test_platform_role_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.update');
        $platformRole = Role::factory()->platform()->create(['name' => 'Platform Role']);

        $this->actingAs($user)->patchJson($this->url($tenant, "roles/{$platformRole->id}"), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertDatabaseHas('roles', ['id' => $platformRole->id, 'name' => 'Platform Role']);
    }

    public function test_system_role_cannot_be_edited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.update');
        $systemRole = Role::factory()->system()->create(['tenant_id' => $tenant->id, 'name' => 'Tenant Admin']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "roles/{$systemRole->id}"), ['name' => 'Hijacked']);

        $response->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $systemRole->id, 'name' => 'Tenant Admin']);
    }

    public function test_role_slug_cannot_be_edited_after_creation(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.update');
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'original-slug']);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "roles/{$role->id}"), [
            'name' => $role->name,
            'slug' => 'forged-new-slug',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'original-slug');
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'slug' => 'original-slug']);
    }

    public function test_tenant_role_cannot_be_moved_to_another_tenant_via_update(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.update');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "roles/{$role->id}"), [
            'name' => $role->name,
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('roles', ['id' => $role->id, 'tenant_id' => $tenant->id]);
    }

    public function test_tenant_a_cannot_update_tenant_b_role(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'roles.update');
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Untouched']);

        $this->actingAs($userA)->patchJson($this->url($tenantA, "roles/{$roleB->id}"), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertDatabaseHas('roles', ['id' => $roleB->id, 'name' => 'Untouched']);
    }

    // ---- Checkpoint 28: seeded/created role is_system_role facts ----

    public function test_seeded_roles_are_marked_as_system_roles(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();
        $seededRole = Role::query()->where('tenant_id', $tenant->id)->where('slug', 'tenant-admin')->firstOrFail();

        $this->assertTrue($seededRole->is_system_role);
    }

    // ---- Checkpoint 28: no delete route exists ----

    public function test_no_role_delete_route_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.update');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "roles/{$role->id}"));

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
