<?php

namespace Tests\Feature\Leave;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LeaveBalanceApiTest extends TestCase
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

    protected function linkedUser(Tenant $tenant, string ...$permissionKeys): array
    {
        $user = $this->userWithPermissions($tenant, ...$permissionKeys);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        return [$user, $employee];
    }

    protected function url(Tenant $tenant, string $path): string
    {
        return 'http://'.$tenant->subdomain.'.'.config('tenancy.base_domain').'/api/v1/'.$path;
    }

    // 1: Admin can create leave balance for same-tenant employee
    public function test_admin_can_create_leave_balance_for_same_tenant_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.create');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, 'leave-balances'), [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2027,
            'entitlement_days' => 21,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_balances', [
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'year' => 2027, 'entitlement_days' => 21.00,
        ]);
        $this->assertEquals(21.0, $response->json('data.available_days'));
    }

    // 2, security #2: Admin cannot create balance for another tenant's employee
    public function test_admin_cannot_create_balance_for_another_tenants_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'leave_balances.create');
        $employeeB = Employee::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);
        $leaveType = LeaveType::factory()->recycle($tenantA)->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenantA, 'leave-balances'), [
            'employee_id' => $employeeB->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2027,
            'entitlement_days' => 21,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    // 3, security #3: Admin cannot create balance using another tenant's leave type
    public function test_admin_cannot_create_balance_using_another_tenants_leave_type(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'leave_balances.create');
        $employee = Employee::factory()->recycle($tenantA)->create(['tenant_id' => $tenantA->id]);
        $leaveTypeB = LeaveType::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenantA, 'leave-balances'), [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveTypeB->id,
            'year' => 2027,
            'entitlement_days' => 21,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    // 4: Duplicate employee/leave type/year balance is rejected
    public function test_duplicate_balance_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.create');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        LeaveBalance::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2027,
        ]);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, 'leave-balances'), [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2027,
            'entitlement_days' => 21,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('year');
    }

    // 5: Admin can update entitlement/carried forward/adjustment
    public function test_admin_can_update_entitlement_carried_forward_adjustment(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.update', 'leave_balances.adjust');
        $balance = LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'entitlement_days' => 20]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), [
            'entitlement_days' => 25,
            'carried_forward_days' => 3,
            'adjustment_days' => 2,
        ]);

        $response->assertOk();
        $balance->refresh();
        $this->assertSame('25.00', $balance->entitlement_days);
        $this->assertSame('3.00', $balance->carried_forward_days);
        $this->assertSame('2.00', $balance->adjustment_days);
    }

    // Refinement 5: adjustment_days requires leave_balances.adjust specifically
    public function test_update_without_adjust_permission_cannot_change_adjustment_days(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.update');
        $balance = LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), [
            'adjustment_days' => 5,
        ]);

        $response->assertForbidden();
        $this->assertSame('0.00', $balance->fresh()->adjustment_days);
    }

    // Refinement 5: cannot change used_days/pending_days/employee_id/leave_type_id/year/tenant_id via PATCH
    public function test_update_cannot_change_protected_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.update');
        $otherEmployee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $otherTenant = Tenant::factory()->create();
        $balance = LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'used_days' => 2, 'pending_days' => 1]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), [
            'used_days' => 999,
            'pending_days' => 999,
            'employee_id' => $otherEmployee->id,
            'year' => 1999,
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertOk();
        $balance->refresh();
        $this->assertSame('2.00', $balance->used_days);
        $this->assertSame('1.00', $balance->pending_days);
        $this->assertSame($tenant->id, $balance->tenant_id);
    }

    // Refinement 6: admin update cannot create a negative available balance
    public function test_update_cannot_create_negative_available_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.update');
        $balance = LeaveBalance::factory()->recycle($tenant)->create([
            'tenant_id' => $tenant->id, 'entitlement_days' => 10, 'used_days' => 8, 'pending_days' => 2,
        ]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), [
            'entitlement_days' => 5,
        ]);

        $response->assertStatus(422);
        $this->assertSame('10.00', $balance->fresh()->entitlement_days);
    }

    // 6: user without permission cannot create/update balances
    public function test_user_without_permission_cannot_create_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'leave-balances'), [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2027, 'entitlement_days' => 20,
        ]);

        $response->assertForbidden();
    }

    public function test_user_without_permission_cannot_update_balance(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $balance = LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), ['entitlement_days' => 30]);

        $response->assertForbidden();
    }

    // Employee cannot update own balance (explicit admin-rules requirement)
    public function test_employee_cannot_update_own_balance(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant, 'leave.view');
        $balance = LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), ['entitlement_days' => 999]);

        $response->assertForbidden();
    }

    // 7: employee can view own leave balances
    public function test_employee_can_view_own_leave_balances(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $employee] = $this->linkedUser($tenant);
        LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/leave-balances'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    // 8: employee cannot view another employee's balances
    public function test_employee_cannot_view_another_employees_balances(): void
    {
        $tenant = Tenant::factory()->create();
        [$user] = $this->linkedUser($tenant);
        $otherEmployee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id, 'employee_id' => $otherEmployee->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/leave-balances'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // Safe response for user without linked employee (consistent with /me/direct-reports)
    public function test_user_without_linked_employee_receives_safe_response(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/leave-balances'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 9, security #1/#9: Tenant A cannot view Tenant B balance by guessed ID / list
    public function test_tenant_a_cannot_view_tenant_b_balance_by_guessed_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = $this->userWithPermissions($tenantA, 'leave_balances.view');
        $balanceB = LeaveBalance::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($adminA)->getJson($this->url($tenantA, "leave-balances/{$balanceB->id}"));

        $response->assertNotFound();
    }

    public function test_tenant_a_cannot_list_tenant_b_balances(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = $this->userWithPermissions($tenantA, 'leave_balances.view_all');
        LeaveBalance::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($adminA)->getJson($this->url($tenantA, 'leave-balances'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // Security #4: Tenant A cannot update Tenant B balance
    public function test_tenant_a_cannot_update_tenant_b_balance(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = $this->userWithPermissions($tenantA, 'leave_balances.update');
        $balanceB = LeaveBalance::factory()->recycle($tenantB)->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($adminA)->patchJson($this->url($tenantA, "leave-balances/{$balanceB->id}"), ['entitlement_days' => 30]);

        $response->assertNotFound();
    }

    // Security #5: request body tenant_id cannot force cross-tenant creation
    public function test_tenant_id_in_request_body_cannot_force_cross_tenant_creation(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'leave_balances.create');
        $employee = Employee::factory()->recycle($tenantA)->create(['tenant_id' => $tenantA->id]);
        $leaveType = LeaveType::factory()->recycle($tenantA)->create(['tenant_id' => $tenantA->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenantA, 'leave-balances'), [
            'tenant_id' => $tenantB->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2027,
            'entitlement_days' => 20,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_balances', ['tenant_id' => $tenantA->id, 'employee_id' => $employee->id]);
    }

    // Security #6: user without leave_balances.view_all cannot list all balances
    public function test_user_without_view_all_cannot_list_balances(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'leave-balances'));

        $response->assertForbidden();
    }

    // Create/update writes audit log, with safe metadata only
    public function test_create_balance_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.create');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->postJson($this->url($tenant, 'leave-balances'), [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2027, 'entitlement_days' => 20,
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'leave_balance.created')->firstOrFail();
        $this->assertSame($employee->id, $log->metadata['employee_id']);
        $this->assertSame($leaveType->id, $log->metadata['leave_type_id']);
        $this->assertSame(2027, $log->metadata['year']);
    }

    public function test_adjust_writes_leave_balance_adjusted_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.update', 'leave_balances.adjust');
        $balance = LeaveBalance::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "leave-balances/{$balance->id}"), ['adjustment_days' => 3])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'leave_balance.adjusted']);
    }

    // 25/26: all leave-balance routes include tenant.matches; full route audit still passes
    public function test_all_leave_balance_routes_include_tenant_matches(): void
    {
        $uris = ['api/v1/leave-balances', 'api/v1/leave-balances/{leaveBalance}', 'api/v1/me/leave-balances'];
        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), $uris));

        $this->assertGreaterThanOrEqual(3, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('tenant.matches', $route->gatherMiddleware());
        }
    }

    public function test_full_api_route_audit_still_passes(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'));

        $this->assertGreaterThan(30, $routes->count());

        foreach ($routes as $route) {
            $this->assertContains(
                'tenant.matches',
                $route->gatherMiddleware(),
                "Route [{$route->methods()[0]} {$route->uri()}] is missing tenant.matches middleware.",
            );
        }
    }

    // 27/28: inactive user / inactive tenant fail closed
    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.create');
        $admin->update(['status' => User::STATUS_INACTIVE]);
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, 'leave-balances'), [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2027, 'entitlement_days' => 20,
        ]);

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'leave_balances.create');
        $employee = Employee::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $leaveType = LeaveType::factory()->recycle($tenant)->create(['tenant_id' => $tenant->id]);
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, 'leave-balances'), [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => 2027, 'entitlement_days' => 20,
        ]);

        $response->assertForbidden();
    }
}
