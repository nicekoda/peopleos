<?php

namespace Tests\Feature\UsersAccess;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 23 — POST /api/v1/users/{user}/roles,
 * DELETE /api/v1/users/{user}/roles/{role}. The most security-sensitive
 * surface in this checkpoint (Refinement 5/9) — every rule here is
 * layered: FormRequest validation, User::assignRole()/removeRole()'s
 * own guards, and TenantAdminProtectionService.
 */
class UserRoleApiTest extends TestCase
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

    public function test_user_without_assign_role_permission_cannot_assign_role(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.view');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($actor)->postJson($this->url($tenant, "users/{$target->id}/roles"), ['role_id' => $role->id])
            ->assertForbidden();

        $this->assertFalse($target->fresh()->roles()->where('roles.id', $role->id)->exists());
    }

    public function test_user_with_assign_role_permission_can_assign_same_tenant_role(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenant, "users/{$target->id}/roles"), ['role_id' => $role->id]);

        $response->assertOk();
        $this->assertTrue($target->fresh()->roles()->where('roles.id', $role->id)->exists());
    }

    public function test_cannot_assign_platform_role_to_tenant_user(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);
        $platformRole = Role::factory()->platform()->create();

        $response = $this->actingAs($actor)->postJson($this->url($tenant, "users/{$target->id}/roles"), ['role_id' => $platformRole->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('role_id');
        $this->assertFalse($target->fresh()->roles()->where('roles.id', $platformRole->id)->exists());
    }

    public function test_cannot_assign_another_tenants_role(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenantA, 'users.assign_role');
        $target = User::factory()->create(['tenant_id' => $tenantA->id]);
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($actor)->postJson($this->url($tenantA, "users/{$target->id}/roles"), ['role_id' => $roleB->id]);

        $response->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_cannot_assign_role_to_tenant_b_user_via_tenant_a_session(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenantA, 'users.assign_role');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $roleA = Role::factory()->create(['tenant_id' => $tenantA->id]);

        $this->actingAs($actor)->postJson($this->url($tenantA, "users/{$userB->id}/roles"), ['role_id' => $roleA->id])
            ->assertNotFound();
    }

    public function test_assigning_role_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($actor)->postJson($this->url($tenant, "users/{$target->id}/roles"), ['role_id' => $role->id])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.assigned',
            'target_user_id' => $target->id,
        ]);
    }

    public function test_removing_role_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $target->assignRole($role);

        $this->actingAs($actor)->deleteJson($this->url($tenant, "users/{$target->id}/roles/{$role->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.removed',
            'target_user_id' => $target->id,
        ]);
        $this->assertFalse($target->fresh()->roles()->where('roles.id', $role->id)->exists());
    }

    public function test_cannot_remove_last_tenant_admin_role(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $adminRole = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'tenant-admin', 'name' => 'Tenant Admin']);
        $admin->assignRole($adminRole);

        $response = $this->actingAs($actor)->deleteJson($this->url($tenant, "users/{$admin->id}/roles/{$adminRole->id}"));

        $response->assertStatus(409);
        $this->assertTrue($admin->fresh()->roles()->where('roles.id', $adminRole->id)->exists());
    }

    public function test_can_remove_tenant_admin_role_when_another_admin_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $adminRole = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'tenant-admin', 'name' => 'Tenant Admin']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole($adminRole);
        $secondAdmin = User::factory()->create(['tenant_id' => $tenant->id]);
        $secondAdmin->assignRole($adminRole);

        $response = $this->actingAs($actor)->deleteJson($this->url($tenant, "users/{$admin->id}/roles/{$adminRole->id}"));

        $response->assertOk();
        $this->assertFalse($admin->fresh()->roles()->where('roles.id', $adminRole->id)->exists());
    }

    public function test_removing_non_admin_role_is_unaffected_by_admin_protection(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $target = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $target->assignRole($role);

        $this->actingAs($actor)->deleteJson($this->url($tenant, "users/{$target->id}/roles/{$role->id}"))->assertOk();
    }

    public function test_platform_admin_users_cannot_be_changed_through_tenant_role_endpoints(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->userWithPermissions($tenant, 'users.assign_role');
        $platformAdmin = User::factory()->platformAdmin()->create();
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($actor)->postJson($this->url($tenant, "users/{$platformAdmin->id}/roles"), ['role_id' => $role->id])
            ->assertNotFound();
    }
}
