<?php

namespace Tests\Feature\Employees;

use App\Enums\DepartmentStatus;
use App\Enums\EmploymentType;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermission(Tenant $tenant, string ...$permissionKeys): User
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

    protected function validEmployeePayload(array $overrides = []): array
    {
        return array_merge([
            'employee_number' => 'EMP-0001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'work_email' => 'ada@example.com',
            'personal_email' => 'ada.personal@example.com',
            'phone' => '555-0100',
            'employment_type' => EmploymentType::FullTime->value,
        ], $overrides);
    }

    public function test_user_with_create_permission_can_create_employee_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload());

        $response->assertCreated();
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-0001', 'tenant_id' => $tenant->id]);
    }

    public function test_user_without_create_permission_cannot_create_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload());

        $response->assertForbidden();
        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-0001']);
    }

    public function test_user_with_view_permission_can_list_employees_in_own_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.view');
        Employee::factory()->count(2)->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'employees'));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_user_cannot_list_employees_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermission($tenantA, 'employees.view');
        Employee::factory()->create(['tenant_id' => $tenantA->id, 'employee_number' => 'A-1']);
        Employee::factory()->create(['tenant_id' => $tenantB->id, 'employee_number' => 'B-1']);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, 'employees'));

        $response->assertOk();
        $numbers = collect($response->json('data'))->pluck('employee_number');
        $this->assertTrue($numbers->contains('A-1'));
        $this->assertFalse($numbers->contains('B-1'));
    }

    public function test_user_cannot_view_employee_from_another_tenant_by_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermission($tenantA, 'employees.view');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->getJson($this->url($tenantA, "employees/{$employeeB->id}"));

        $response->assertNotFound();
    }

    public function test_user_cannot_update_employee_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermission($tenantA, 'employees.update');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id, 'first_name' => 'Original']);

        $response = $this->actingAs($user)
            ->patchJson($this->url($tenantA, "employees/{$employeeB->id}"), ['first_name' => 'Hacked']);

        $response->assertNotFound();
        $this->assertSame('Original', $employeeB->fresh()->first_name);
    }

    public function test_user_cannot_delete_employee_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $user = $this->userWithPermission($tenantA, 'employees.delete');
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($user)->deleteJson($this->url($tenantA, "employees/{$employeeB->id}"));

        $response->assertNotFound();
        $this->assertNull($employeeB->fresh()->deleted_at);
    }

    public function test_request_body_tenant_id_cannot_force_creation_in_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, 'employees'),
            $this->validEmployeePayload(['tenant_id' => $otherTenant->id]),
        );

        $response->assertCreated();
        $this->assertDatabaseHas('employees', ['employee_number' => 'EMP-0001', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseMissing('employees', ['employee_number' => 'EMP-0001', 'tenant_id' => $otherTenant->id]);
    }

    public function test_employee_number_must_be_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_number' => 'DUPLICATE']);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, 'employees'),
            $this->validEmployeePayload(['employee_number' => 'DUPLICATE', 'work_email' => 'someone-else@example.com']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors('employee_number');
    }

    public function test_same_employee_number_can_exist_in_different_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        Employee::factory()->create(['tenant_id' => $tenantA->id, 'employee_number' => 'SHARED']);
        $user = $this->userWithPermission($tenantB, 'employees.create');

        $response = $this->actingAs($user)->postJson(
            $this->url($tenantB, 'employees'),
            $this->validEmployeePayload(['employee_number' => 'SHARED', 'work_email' => 'shared@example.com']),
        );

        $response->assertCreated();
    }

    public function test_work_email_must_be_unique_within_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');
        Employee::factory()->create(['tenant_id' => $tenant->id, 'work_email' => 'taken@example.com']);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, 'employees'),
            $this->validEmployeePayload(['employee_number' => 'EMP-9999', 'work_email' => 'taken@example.com']),
        );

        $response->assertStatus(422)->assertJsonValidationErrors('work_email');
    }

    public function test_invalid_department_location_or_position_from_another_tenant_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');

        $foreignDepartment = Department::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignLocation = Location::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignPosition = Position::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['department_id' => $foreignDepartment->id]))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['location_id' => $foreignLocation->id]))
            ->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['position_id' => $foreignPosition->id]))
            ->assertStatus(422)->assertJsonValidationErrors('position_id');
    }

    /**
     * Checkpoint 32 — the archived-row validation fix. Rule::exists()
     * is a raw DB check that bypasses Eloquent's SoftDeletes scope
     * entirely, so an archived (soft-deleted) or inactive department/
     * location/position must be excluded explicitly — same pattern
     * fixed for document_category_id in Checkpoint 9. An employee must
     * never be assignable to an archived lookup value going forward.
     */
    public function test_archived_department_cannot_be_assigned_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');
        $softDeletedDepartment = Department::factory()->create(['tenant_id' => $tenant->id]);
        $softDeletedDepartment->delete();
        $inactiveDepartment = Department::factory()->inactive()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['employee_number' => 'EMP-1001', 'department_id' => $softDeletedDepartment->id]))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['employee_number' => 'EMP-1002', 'department_id' => $inactiveDepartment->id]))
            ->assertStatus(422)->assertJsonValidationErrors('department_id');
    }

    public function test_archived_position_cannot_be_assigned_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');
        $softDeletedPosition = Position::factory()->create(['tenant_id' => $tenant->id]);
        $softDeletedPosition->delete();
        $inactivePosition = Position::factory()->inactive()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['employee_number' => 'EMP-1003', 'position_id' => $softDeletedPosition->id]))
            ->assertStatus(422)->assertJsonValidationErrors('position_id');

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['employee_number' => 'EMP-1004', 'position_id' => $inactivePosition->id]))
            ->assertStatus(422)->assertJsonValidationErrors('position_id');
    }

    public function test_archived_location_cannot_be_assigned_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');
        $softDeletedLocation = Location::factory()->create(['tenant_id' => $tenant->id]);
        $softDeletedLocation->delete();
        $inactiveLocation = Location::factory()->inactive()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['employee_number' => 'EMP-1005', 'location_id' => $softDeletedLocation->id]))
            ->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->actingAs($user)
            ->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload(['employee_number' => 'EMP-1006', 'location_id' => $inactiveLocation->id]))
            ->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    /**
     * An employee already assigned to a department is unaffected when
     * that department is later archived — the archived-row exclusion
     * only blocks new or changed assignment, not existing ones (see
     * StoreEmployeeRequest/UpdateEmployeeRequest — this field is
     * `nullable` with no `sometimes`, so it's only validated when the
     * request actually provides it).
     */
    public function test_updating_unrelated_employee_field_does_not_revalidate_an_already_archived_department(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.update');
        $department = Department::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $department->id]);
        $department->update(['status' => DepartmentStatus::Inactive]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'first_name' => 'Updated',
        ]);

        $response->assertOk();
        $this->assertSame($department->id, $employee->fresh()->department_id);
    }

    public function test_employee_resource_returns_resolved_department_location_position_names(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.view');
        $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Lagos HQ']);
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Software Engineer']);
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'department_id' => $department->id,
            'location_id' => $location->id,
            'position_id' => $position->id,
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.department.id', $department->id);
        $response->assertJsonPath('data.department.name', 'Engineering');
        $response->assertJsonPath('data.location.name', 'Lagos HQ');
        $response->assertJsonPath('data.position.name', 'Software Engineer');
        // Raw IDs kept for backward compatibility.
        $response->assertJsonPath('data.department_id', $department->id);
    }

    public function test_employee_resource_returns_null_department_location_position_when_unassigned(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.view');
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'department_id' => null,
            'location_id' => null,
            'position_id' => null,
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.department', null);
        $response->assertJsonPath('data.location', null);
        $response->assertJsonPath('data.position', null);
    }

    /**
     * Checkpoint 43 — linked_user mirrors UserResource's own
     * linked_employee shape in reverse: null unless a user account is
     * actually linked, otherwise {id, name} only, never the full User
     * record (no email/status/roles).
     */
    public function test_employee_resource_exposes_linked_user_id_and_name_only(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = $this->userWithPermission($tenant, 'employees.view');
        $linkedUser = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $linkedUser->id]);

        $response = $this->actingAs($viewer)->getJson($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.linked_user.id', $linkedUser->id);
        $response->assertJsonPath('data.linked_user.name', 'Ada Lovelace');
        $this->assertStringNotContainsString('ada@example.com', json_encode($response->json('data.linked_user')));
    }

    public function test_employee_resource_linked_user_is_null_when_unlinked(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = $this->userWithPermission($tenant, 'employees.view');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => null]);

        $response = $this->actingAs($viewer)->getJson($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $response->assertJsonPath('data.linked_user', null);
    }

    /**
     * manager_employee_id is deliberately not a validated field on
     * create as of Checkpoint 13 — a stray value in the request body is
     * silently ignored, not honored and not rejected. Manager
     * assignment (including cross-tenant rejection) is now exclusively
     * handled by PATCH /employees/{employee}/manager — see
     * ManagerHierarchyTest and docs/security.md.
     */
    public function test_manager_employee_id_in_create_request_body_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');
        $foreignManager = Employee::factory()->create(['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($user)->postJson(
            $this->url($tenant, 'employees'),
            $this->validEmployeePayload(['manager_employee_id' => $foreignManager->id]),
        );

        $response->assertCreated();
        $this->assertNull($response->json('data.manager_employee_id'));
    }

    public function test_create_action_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'employees'), $this->validEmployeePayload())
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.created',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_update_action_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.update');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Before']);

        $this->actingAs($user)
            ->patchJson($this->url($tenant, "employees/{$employee->id}"), ['first_name' => 'After'])
            ->assertOk();

        $log = AuditLog::query()->where('action', 'employee.updated')->where('auditable_id', $employee->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('Before', $log->old_values['first_name']);
        $this->assertSame('After', $log->new_values['first_name']);
    }

    public function test_delete_action_writes_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.delete');
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->deleteJson($this->url($tenant, "employees/{$employee->id}"))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.deleted',
            'module' => 'employees',
            'actor_user_id' => $user->id,
            'auditable_id' => $employee->id,
        ]);
        $this->assertNotNull($employee->fresh()->deleted_at);
    }

    public function test_inactive_user_cannot_access_employee_endpoints(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.view');
        $user->update(['status' => User::STATUS_INACTIVE]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'employees'));

        $response->assertForbidden();
    }

    public function test_user_under_inactive_tenant_cannot_access_employee_endpoints(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermission($tenant, 'employees.view');
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'employees'));

        $response->assertForbidden();
    }
}
