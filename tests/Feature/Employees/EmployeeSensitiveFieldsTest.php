<?php

namespace Tests\Feature\Employees;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dedicated coverage for the employees.view_sensitive gating decision
 * made in Checkpoint 6 and required to be explicitly tested in
 * Checkpoint 7: personal_email and phone are treated as sensitive;
 * work_email is not. See docs/security.md#employee-records.
 */
class EmployeeSensitiveFieldsTest extends TestCase
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

    public function test_user_with_view_sensitive_can_see_sensitive_fields_on_detail_endpoint(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.view_sensitive');
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'personal_email' => 'private@example.com',
            'phone' => '555-1234',
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $this->assertSame('private@example.com', $response->json('data.personal_email'));
        $this->assertSame('555-1234', $response->json('data.phone'));
    }

    public function test_user_without_view_sensitive_cannot_see_sensitive_fields_on_detail_endpoint(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'personal_email' => 'private@example.com',
            'phone' => '555-1234',
            'work_email' => 'work@example.com',
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));

        $response->assertOk();
        $this->assertNull($response->json('data.personal_email'));
        $this->assertNull($response->json('data.phone'));
        // work_email is not sensitive — always visible with employees.view.
        $this->assertSame('work@example.com', $response->json('data.work_email'));
    }

    public function test_employee_list_endpoint_does_not_leak_sensitive_fields_to_unauthorised_users(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view');
        Employee::factory()->count(3)->create([
            'tenant_id' => $tenant->id,
            'personal_email' => 'private@example.com',
            'phone' => '555-1234',
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'employees'));

        $response->assertOk();
        foreach ($response->json('data') as $row) {
            $this->assertNull($row['personal_email']);
            $this->assertNull($row['phone']);
        }
    }

    public function test_employee_list_endpoint_shows_sensitive_fields_with_view_sensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.view_sensitive');
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'personal_email' => 'private@example.com',
            'phone' => '555-1234',
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'employees'));

        $response->assertOk();
        $this->assertSame('private@example.com', $response->json('data.0.personal_email'));
        $this->assertSame('555-1234', $response->json('data.0.phone'));
    }

    public function test_audit_log_for_employee_update_masks_sensitive_field_changes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.update');
        $employee = Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'personal_email' => 'old-private@example.com',
            'phone' => '555-0000',
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'personal_email' => 'new-private@example.com',
            'phone' => '555-9999',
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'employee.updated')->where('auditable_id', $employee->id)->firstOrFail();

        $this->assertSame('***MASKED***', $log->old_values['personal_email']);
        $this->assertSame('***MASKED***', $log->new_values['personal_email']);
        $this->assertSame('***MASKED***', $log->old_values['phone']);
        $this->assertSame('***MASKED***', $log->new_values['phone']);
    }

    public function test_audit_log_for_employee_creation_masks_sensitive_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.create');

        $this->actingAs($user)->postJson($this->url($tenant, 'employees'), [
            'employee_number' => 'EMP-SENS-1',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'employment_type' => 'full_time',
            'personal_email' => 'private@example.com',
            'phone' => '555-1234',
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'employee.created')->latest('id')->firstOrFail();

        $this->assertSame('***MASKED***', $log->new_values['personal_email']);
        $this->assertSame('***MASKED***', $log->new_values['phone']);
    }
}
