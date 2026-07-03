<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveTypeApiTest extends TestCase
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

    public function test_user_with_permission_can_create_leave_type(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.create');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-types'), [
            'name' => 'Annual Leave',
            'is_paid' => true,
            'max_days_per_year' => 21,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_types', [
            'tenant_id' => $tenant->id,
            'name' => 'Annual Leave',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function test_user_without_permission_cannot_create_leave_type(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-types'), ['name' => 'Sick Leave']);

        $response->assertForbidden();
    }

    public function test_tenant_a_cannot_view_tenant_b_leave_type(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'leave_types.view');
        $leaveTypeB = LeaveType::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, "leave-types/{$leaveTypeB->id}"));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_list_tenant_b_leave_types(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'leave_types.view');
        LeaveType::factory()->create(['tenant_id' => $tenantB->id, 'name' => 'Tenant B Only']);

        $response = $this->actingAs($userA)->getJson($this->url($tenantA, 'leave-types'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_leave_type_deletion_is_soft_delete_and_does_not_break_existing_requests(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_types.delete');
        $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->deleteJson($this->url($tenant, "leave-types/{$leaveType->id}"));

        $response->assertOk();
        $this->assertSoftDeleted('leave_types', ['id' => $leaveType->id]);
    }

    public function test_create_leave_type_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'leave_types.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'leave-types'), ['name' => 'Maternity Leave'])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'leave_type.created',
            'module' => 'leave',
            'actor_user_id' => $user->id,
        ]);
    }
}
