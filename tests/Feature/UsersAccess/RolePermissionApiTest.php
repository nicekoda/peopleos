<?php

namespace Tests\Feature\UsersAccess;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 28 — POST /api/v1/roles/{role}/permissions,
 * DELETE /api/v1/roles/{role}/permissions/{permission}. Gated by
 * permissions.assign (the existing, previously-unused permission key —
 * roles.assign_permission does not exist in this app's catalog). Every
 * test here proves a layer of the "safer MVP" lockdown: a permission
 * can only ever be assigned to/removed from a custom (is_system_role:
 * false), current-tenant, non-platform role, and only ever a tenant-safe
 * (non-platform-only) permission — see docs/security.md.
 */
class RolePermissionApiTest extends TestCase
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

    protected function tenantSafePermission(string $key = 'leave.view'): Permission
    {
        return Permission::query()->firstOrCreate(
            ['key' => $key],
            ['category' => explode('.', $key)[0], 'is_platform_permission' => false],
        );
    }

    // ---- assign (store) ----

    public function test_user_without_permissions_assign_cannot_assign_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();

        $this->actingAs($user)
            ->postJson($this->url($tenant, "roles/{$role->id}/permissions"), ['permission_id' => $permission->id])
            ->assertForbidden();

        $this->assertDatabaseMissing('role_permission', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    }

    public function test_user_with_permissions_assign_can_assign_tenant_safe_permission_to_custom_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();

        $response = $this->actingAs($user)->postJson($this->url($tenant, "roles/{$role->id}/permissions"), [
            'permission_id' => $permission->id,
        ]);

        $response->assertOk();
        $this->assertContains($permission->key, collect($response->json('data.permissions'))->pluck('key'));
        $this->assertDatabaseHas('role_permission', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    }

    public function test_permission_assignment_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();

        $baseline = AuditLog::query()->where('tenant_id', $tenant->id)->count();

        $this->actingAs($user)->postJson($this->url($tenant, "roles/{$role->id}/permissions"), [
            'permission_id' => $permission->id,
        ])->assertOk();

        $this->assertSame($baseline + 1, AuditLog::query()->where('tenant_id', $tenant->id)->count());
        $log = AuditLog::query()->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertSame('role.permission_assigned', $log->action);
        $this->assertSame($user->id, $log->actor_user_id);
    }

    public function test_user_cannot_assign_permission_to_system_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $systemRole = Role::factory()->system()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();

        $response = $this->actingAs($user)->postJson($this->url($tenant, "roles/{$systemRole->id}/permissions"), [
            'permission_id' => $permission->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('role_permission', ['role_id' => $systemRole->id, 'permission_id' => $permission->id]);
    }

    public function test_user_cannot_assign_platform_only_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $platformPermission = Permission::query()->firstOrCreate(
            ['key' => 'platform.tenants.view'],
            ['category' => 'platform', 'is_platform_permission' => true],
        );

        $response = $this->actingAs($user)->postJson($this->url($tenant, "roles/{$role->id}/permissions"), [
            'permission_id' => $platformPermission->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('role_permission', ['role_id' => $role->id, 'permission_id' => $platformPermission->id]);
    }

    public function test_user_cannot_assign_permission_to_another_tenants_role(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'permissions.assign');
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);
        $permission = $this->tenantSafePermission();

        $response = $this->actingAs($userA)->postJson($this->url($tenantA, "roles/{$roleB->id}/permissions"), [
            'permission_id' => $permission->id,
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('role_permission', ['role_id' => $roleB->id, 'permission_id' => $permission->id]);
    }

    public function test_user_cannot_assign_permission_to_a_platform_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $platformRole = Role::factory()->platform()->create();
        $permission = $this->tenantSafePermission();

        $this->actingAs($user)
            ->postJson($this->url($tenant, "roles/{$platformRole->id}/permissions"), ['permission_id' => $permission->id])
            ->assertNotFound();
    }

    // ---- remove (destroy) ----

    public function test_user_without_permissions_assign_cannot_remove_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'roles.view');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();
        $role->givePermissionTo($permission);

        $this->actingAs($user)
            ->deleteJson($this->url($tenant, "roles/{$role->id}/permissions/{$permission->id}"))
            ->assertForbidden();

        $this->assertDatabaseHas('role_permission', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    }

    public function test_user_can_remove_tenant_safe_permission_from_custom_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();
        $role->givePermissionTo($permission);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "roles/{$role->id}/permissions/{$permission->id}"));

        $response->assertOk();
        $this->assertNotContains($permission->key, collect($response->json('data.permissions'))->pluck('key'));
        $this->assertDatabaseMissing('role_permission', ['role_id' => $role->id, 'permission_id' => $permission->id]);
    }

    public function test_permission_removal_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();
        $role->givePermissionTo($permission);

        $baseline = AuditLog::query()->where('tenant_id', $tenant->id)->count();

        $this->actingAs($user)->deleteJson($this->url($tenant, "roles/{$role->id}/permissions/{$permission->id}"))->assertOk();

        $this->assertSame($baseline + 1, AuditLog::query()->where('tenant_id', $tenant->id)->count());
        $log = AuditLog::query()->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertSame('role.permission_removed', $log->action);
    }

    public function test_user_cannot_remove_permission_from_system_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $systemRole = Role::factory()->system()->create(['tenant_id' => $tenant->id]);
        $permission = $this->tenantSafePermission();
        $systemRole->givePermissionTo($permission);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "roles/{$systemRole->id}/permissions/{$permission->id}"));

        $response->assertForbidden();
        $this->assertDatabaseHas('role_permission', ['role_id' => $systemRole->id, 'permission_id' => $permission->id]);
    }

    public function test_user_cannot_remove_permission_from_another_tenants_role(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'permissions.assign');
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);
        $permission = $this->tenantSafePermission();
        $roleB->givePermissionTo($permission);

        $response = $this->actingAs($userA)->deleteJson($this->url($tenantA, "roles/{$roleB->id}/permissions/{$permission->id}"));

        $response->assertNotFound();
        $this->assertDatabaseHas('role_permission', ['role_id' => $roleB->id, 'permission_id' => $permission->id]);
    }

    // ---- last admin / Tenant Admin lockout protection ----

    /**
     * The "safer MVP" makes this structurally impossible rather than
     * runtime-checked: Tenant Admin is always a system role (RoleSeeder
     * sets is_system_role: true on every seeded role), and system roles
     * can never reach assignPermission()/removePermission() at all —
     * confirmed directly against the real seeded Tenant Admin role.
     */
    public function test_built_in_tenant_admin_role_cannot_be_made_unsafe(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'permissions.assign');
        $tenantAdminRole = Role::factory()->system()->create(['tenant_id' => $tenant->id, 'slug' => 'tenant-admin', 'name' => 'Tenant Admin']);
        $usersViewPermission = $this->tenantSafePermission('users.view');
        $tenantAdminRole->givePermissionTo($usersViewPermission);

        // Cannot add a new permission to it.
        $this->actingAs($user)
            ->postJson($this->url($tenant, "roles/{$tenantAdminRole->id}/permissions"), ['permission_id' => $this->tenantSafePermission('roles.view')->id])
            ->assertForbidden();

        // Cannot strip an existing permission from it either.
        $this->actingAs($user)
            ->deleteJson($this->url($tenant, "roles/{$tenantAdminRole->id}/permissions/{$usersViewPermission->id}"))
            ->assertForbidden();

        $this->assertDatabaseHas('role_permission', ['role_id' => $tenantAdminRole->id, 'permission_id' => $usersViewPermission->id]);
    }
}
