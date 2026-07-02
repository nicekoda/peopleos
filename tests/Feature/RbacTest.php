<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_super_admin_has_platform_permissions(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $role = Role::factory()->platform()->create(['slug' => 'super-admin']);
        $permission = Permission::factory()->platform()->create(['key' => 'platform.tenants.view']);
        $role->givePermissionTo($permission);
        $admin->assignRole($role);

        $this->assertTrue($admin->hasPermission('platform.tenants.view'));
    }

    public function test_platform_super_admin_can_exist_without_tenant(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->assertNull($admin->tenant_id);
        $this->assertTrue($admin->is_platform_admin);
    }

    public function test_tenant_admin_has_tenant_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'tenant-admin']);
        $permission = Permission::factory()->create(['key' => 'roles.create']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission('roles.create'));
    }

    public function test_hr_manager_has_hr_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'hr-manager']);
        $role->givePermissionTo(Permission::factory()->create(['key' => 'leave.approve']));
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission('leave.approve'));
        $this->assertFalse($user->hasPermission('roles.delete'));
    }

    public function test_employee_has_only_basic_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'slug' => 'employee']);
        $role->givePermissionTo(Permission::factory()->create(['key' => 'leave.request']));
        $user->assignRole($role);

        $this->assertTrue($user->hasPermission('leave.request'));
        $this->assertFalse($user->hasPermission('employees.delete'));
    }

    public function test_tenant_user_cannot_receive_platform_role(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $platformRole = Role::factory()->platform()->create();

        $this->expectException(RuntimeException::class);

        $user->assignRole($platformRole);
    }

    public function test_tenant_user_cannot_receive_role_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenantA->id]);
        $roleFromB = Role::factory()->create(['tenant_id' => $tenantB->id]);

        $this->expectException(RuntimeException::class);

        $user->assignRole($roleFromB);
    }

    public function test_role_from_tenant_a_does_not_grant_access_to_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $roleA = Role::factory()->create(['tenant_id' => $tenantA->id, 'slug' => 'shared-name']);
        $roleA->givePermissionTo(Permission::factory()->create(['key' => 'employees.view']));

        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $this->expectException(RuntimeException::class);

        $userB->assignRole($roleA);
    }

    public function test_direct_permission_grants_work_only_within_correct_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenantPermission = Permission::factory()->create(['key' => 'documents.download']);
        $platformPermission = Permission::factory()->platform()->create();

        $user->grantPermission($tenantPermission);
        $this->assertTrue($user->hasPermission('documents.download'));

        $this->expectException(RuntimeException::class);

        $user->grantPermission($platformPermission);
    }

    public function test_inactive_user_does_not_pass_permission_checks(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->inactive()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::factory()->create(['key' => 'employees.view']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertFalse($user->hasPermission('employees.view'));
    }

    public function test_user_under_inactive_tenant_does_not_pass_permission_checks(): void
    {
        $tenant = Tenant::factory()->create(['status' => Tenant::STATUS_SUSPENDED]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $permission = Permission::factory()->create(['key' => 'employees.view']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertFalse($user->hasPermission('employees.view'));
    }

    public function test_unknown_permission_returns_false(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertFalse($user->hasPermission('nonexistent.permission'));
    }

    public function test_middleware_rejects_user_without_permission(): void
    {
        Route::middleware(['web', 'auth', 'permission:employees.view'])
            ->get('/__test/protected', fn () => response()->json(['ok' => true]));

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/__test/protected');

        $response->assertForbidden();
    }

    public function test_middleware_allows_user_with_permission(): void
    {
        Route::middleware(['web', 'auth', 'permission:employees.view'])
            ->get('/__test/protected', fn () => response()->json(['ok' => true]));

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);
        $role->givePermissionTo(Permission::factory()->create(['key' => 'employees.view']));
        $user->assignRole($role);

        $response = $this->actingAs($user)
            ->get('http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/__test/protected');

        $response->assertOk();
    }
}
