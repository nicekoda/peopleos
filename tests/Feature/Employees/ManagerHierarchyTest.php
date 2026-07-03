<?php

namespace Tests\Feature\Employees;

use App\Enums\EmployeeStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ManagerHierarchyTest extends TestCase
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

    // 1: Tenant Admin can assign same-tenant manager
    public function test_admin_can_assign_same_tenant_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $manager->id,
        ]);

        $response->assertOk();
        $this->assertSame($manager->id, $employee->fresh()->manager_employee_id);
    }

    // 2: user without permission cannot assign manager
    public function test_user_without_permission_cannot_assign_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $manager->id,
        ]);

        $response->assertForbidden();
        $this->assertNull($employee->fresh()->manager_employee_id);
    }

    // 3: Tenant A cannot assign Tenant B manager
    public function test_tenant_a_cannot_assign_tenant_b_manager(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'employees.update_manager');
        $employeeA = Employee::factory()->create(['tenant_id' => $tenantA->id]);
        $managerB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenantA, "employees/{$employeeA->id}/manager"), [
            'manager_employee_id' => $managerB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_employee_id');
    }

    // 4: Tenant A cannot update manager for Tenant B employee
    public function test_tenant_a_cannot_update_manager_for_tenant_b_employee(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'employees.update_manager');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);
        $managerB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenantA, "employees/{$employeeB->id}/manager"), [
            'manager_employee_id' => $managerB->id,
        ]);

        $response->assertNotFound();
    }

    // 5: self-manager assignment is rejected
    public function test_self_manager_assignment_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $employee->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_employee_id');
    }

    // 6: direct circular relationship rejected (A manages B, B manages A)
    public function test_direct_circular_relationship_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $a->id]);

        // Attempt: A's manager becomes B, while B's manager is already A.
        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$a->id}/manager"), [
            'manager_employee_id' => $b->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_employee_id');
    }

    // 7: indirect circular relationship rejected (A->B->C, then C's manager becomes A)
    public function test_indirect_circular_relationship_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $a = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $b = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $a->id]);
        $c = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $b->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$a->id}/manager"), [
            'manager_employee_id' => $c->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_employee_id');
    }

    // 8: deleted employee cannot be assigned as manager
    public function test_deleted_employee_cannot_be_assigned_as_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $deletedManager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $deletedManager->delete();

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $deletedManager->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_employee_id');
    }

    // 9: inactive/terminated employee cannot be assigned as manager
    public function test_terminated_employee_cannot_be_assigned_as_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $terminatedManager = Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::Terminated]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $terminatedManager->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_employee_id');
    }

    // 10: Tenant Admin can remove manager
    public function test_admin_can_remove_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $manager->id]);

        $response = $this->actingAs($admin)->deleteJson($this->url($tenant, "employees/{$employee->id}/manager"));

        $response->assertOk();
        $this->assertNull($employee->fresh()->manager_employee_id);
    }

    // 11: user without permission cannot remove manager
    public function test_user_without_permission_cannot_remove_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $manager->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenant, "employees/{$employee->id}/manager"));

        $response->assertForbidden();
        $this->assertSame($manager->id, $employee->fresh()->manager_employee_id);
    }

    // 12: manager assignment writes audit log, with safe metadata only
    public function test_manager_assignment_writes_audit_log_with_safe_metadata(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $manager->id,
        ])->assertOk();

        $auditLog = AuditLog::query()->where('action', 'employee.manager_assigned')->firstOrFail();

        $this->assertSame($tenant->id, $auditLog->tenant_id);
        $this->assertSame($admin->id, $auditLog->actor_user_id);
        $this->assertSame($employee->id, $auditLog->metadata['employee_id']);
        $this->assertNull($auditLog->metadata['old_manager_employee_id']);
        $this->assertSame($manager->id, $auditLog->metadata['new_manager_employee_id']);
        // No names/emails/phone numbers in metadata.
        $this->assertStringNotContainsString($employee->first_name, json_encode($auditLog->metadata));
        $this->assertStringNotContainsString($manager->first_name, json_encode($auditLog->metadata));
    }

    // 13: manager removal writes audit log
    public function test_manager_removal_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $manager->id]);

        $this->actingAs($admin)->deleteJson($this->url($tenant, "employees/{$employee->id}/manager"))->assertOk();

        $auditLog = AuditLog::query()->where('action', 'employee.manager_removed')->firstOrFail();
        $this->assertSame($manager->id, $auditLog->metadata['old_manager_employee_id']);
        $this->assertNull($auditLog->metadata['new_manager_employee_id']);
    }

    // Manager change (not first assignment) logs employee.manager_changed
    public function test_manager_change_writes_manager_changed_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $firstManager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $secondManager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $firstManager->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $secondManager->id,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.manager_changed']);
    }

    // 14: linked manager can view own direct reports
    public function test_linked_manager_can_view_own_direct_reports(): void
    {
        $tenant = Tenant::factory()->create();
        $managerUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $managerEmployee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $managerUser->id]);
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $managerEmployee->id]);

        $response = $this->actingAs($managerUser)->getJson($this->url($tenant, 'me/direct-reports'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$report->id], $ids);
    }

    // 15: linked employee with no reports receives empty list
    public function test_linked_employee_with_no_reports_receives_empty_list(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/direct-reports'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 16: user without linked employee receives safe response
    public function test_user_without_linked_employee_receives_safe_response(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'me/direct-reports'));

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // 17: user with employees.view_team can view another employee's direct reports
    public function test_user_with_view_team_can_view_another_employees_direct_reports(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'employees.view_team');
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $manager->id]);

        $response = $this->actingAs($hrUser)->getJson($this->url($tenant, "employees/{$manager->id}/direct-reports"));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$report->id], $ids);
    }

    // 18: user without employees.view_team cannot view another employee's direct reports
    public function test_user_without_view_team_cannot_view_direct_reports(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$manager->id}/direct-reports"));

        $response->assertForbidden();
    }

    // 19: Tenant A cannot view Tenant B direct reports
    public function test_tenant_a_cannot_view_tenant_b_direct_reports(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $hrUserA = $this->userWithPermissions($tenantA, 'employees.view_team');
        $managerB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($hrUserA)->getJson($this->url($tenantA, "employees/{$managerB->id}/direct-reports"));

        $response->assertNotFound();
    }

    // 20: existing employee update endpoint cannot bypass manager validation (Refinement 3)
    public function test_general_employee_update_endpoint_cannot_set_manager(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'manager_employee_id' => $manager->id,
        ]);

        $response->assertOk();
        $this->assertNull($employee->fresh()->manager_employee_id);
    }

    // 21: all new routes include tenant.matches
    public function test_all_new_manager_hierarchy_routes_include_tenant_matches(): void
    {
        $uris = [
            'api/v1/employees/{employee}/manager',
            'api/v1/employees/{employee}/direct-reports',
            'api/v1/employees/{employee}/reporting-tree',
            'api/v1/me/direct-reports',
        ];

        $routes = collect(Route::getRoutes())->filter(fn ($route) => in_array($route->uri(), $uris));

        $this->assertGreaterThanOrEqual(count($uris), $routes->count());

        foreach ($routes as $route) {
            $this->assertContains('tenant.matches', $route->gatherMiddleware());
        }
    }

    // 22: full /api/v1 route audit still passes
    public function test_full_api_route_audit_all_tenant_scoped_routes_include_tenant_matches(): void
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

    // 23: inactive user fails closed
    public function test_inactive_user_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $admin->update(['status' => User::STATUS_INACTIVE]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $manager->id,
        ]);

        $response->assertForbidden();
    }

    // 24: user under inactive tenant fails closed
    public function test_user_under_inactive_tenant_fails_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.update_manager');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $manager = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}/manager"), [
            'manager_employee_id' => $manager->id,
        ]);

        $response->assertForbidden();
    }

    // Additional: reporting tree returns nested structure and respects depth cap semantics
    public function test_reporting_tree_returns_nested_direct_reports(): void
    {
        $tenant = Tenant::factory()->create();
        $hrUser = $this->userWithPermissions($tenant, 'employees.view_team');
        $top = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $mid = Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $top->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'manager_employee_id' => $mid->id]);

        $response = $this->actingAs($hrUser)->getJson($this->url($tenant, "employees/{$top->id}/reporting-tree"));

        $response->assertOk();
        $this->assertSame($top->id, $response->json('data.id'));
        $this->assertCount(1, $response->json('data.direct_reports'));
        $this->assertSame($mid->id, $response->json('data.direct_reports.0.id'));
        $this->assertCount(1, $response->json('data.direct_reports.0.direct_reports'));
    }
}
