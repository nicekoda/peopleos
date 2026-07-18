<?php

namespace Tests\Feature\CustomFields;

use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModuleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 51 — custom fields for Employee (App\Models\Employee),
 * entity #3. Proves the engine built in Checkpoint 48 and the
 * field-level visibility model from Checkpoint 50 are both genuinely
 * reusable a third time: no migration, no service/validator/audit-event
 * change — only a new CustomFieldEntity case plus this entity's own
 * wiring in EmployeeController/EmployeeResource/UpdateEmployeeRequest.
 *
 * Also covers the Checkpoint 51 module-gate fix: custom-fields/employee
 * must work regardless of the Recruitment module's state (Employees is
 * core, never toggleable), while custom-fields/job_application must
 * still correctly depend on it — proving the fix didn't just move the
 * bug rather than actually resolving it.
 */
class CustomFieldEmployeeValueApiTest extends TestCase
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

    protected function textField(Tenant $tenant, User $actor, array $overrides = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'entity_type' => 'employee',
            'field_key' => 'uniform_size',
            'label' => 'Uniform Size',
            'field_type' => 'text',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    // 1: employee is a valid CustomFieldEntity case.
    public function test_employee_is_a_valid_custom_field_entity(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/employee'))->assertOk();
    }

    // 2: Tenant Admin can create an employee field.
    public function test_tenant_admin_can_create_an_employee_field(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/employee'), [
            'field_key' => 'uniform_size', 'label' => 'Uniform Size', 'field_type' => 'text',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.entity_type', 'employee');
        $this->assertDatabaseHas('custom_field_definitions', ['entity_type' => 'employee', 'field_key' => 'uniform_size']);
    }

    // 3: HR Manager can view but not manage employee field definitions.
    public function test_hr_manager_can_view_but_not_manage_employee_definitions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/employee'))->assertOk();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/employee'), [
            'field_key' => 'uniform_size', 'label' => 'Uniform Size', 'field_type' => 'text',
        ])->assertForbidden();
    }

    // 4: employee reserved keys rejected.
    public function test_employee_reserved_keys_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        foreach (['employee_number', 'personal_email', 'manager_employee_id', 'department', 'user_id', 'password'] as $reserved) {
            $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/employee'), [
                'field_key' => $reserved, 'label' => 'X', 'field_type' => 'text',
            ]);
            $response->assertUnprocessable();
        }
    }

    // 5/6: normal employee custom field visible/editable with employees.view/update.
    public function test_normal_employee_field_visible_and_editable_with_parent_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $this->textField($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $update = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.custom_field_values.uniform_size', 'Large');

        $show = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonPath('data.custom_field_values.uniform_size', 'Large');
    }

    // 7: sensitive employee custom field requires access_sensitive.
    public function test_sensitive_employee_field_requires_access_sensitive(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $this->textField($tenant, $owner, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($owner)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'call spouse first'],
        ])->assertOk();

        $noTierUser = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $show = $this->actingAs($noTierUser)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.emergency_contact_note');
        $this->actingAs($noTierUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'overwritten'],
        ])->assertForbidden();
    }

    // 8: confidential employee custom field requires access_confidential.
    public function test_confidential_employee_field_requires_access_confidential(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_confidential');
        $this->textField($tenant, $owner, ['field_key' => 'background_check_note', 'sensitivity' => 'confidential']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($owner)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['background_check_note' => 'clean record'],
        ])->assertOk();

        // No implied hierarchy — access_sensitive alone is not enough.
        $sensitiveOnlyUser = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $show = $this->actingAs($sensitiveOnlyUser)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.background_check_note');
        $this->actingAs($sensitiveOnlyUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['background_check_note' => 'overwritten'],
        ])->assertForbidden();
    }

    // 9: restricted employee custom field requires access_restricted.
    public function test_restricted_employee_field_requires_access_restricted(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_restricted');
        $this->textField($tenant, $owner, ['field_key' => 'executive_compensation_note', 'sensitivity' => 'restricted']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($owner)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['executive_compensation_note' => 'top bracket'],
        ])->assertOk();

        $everyTierExceptRestricted = $this->userWithPermissions(
            $tenant,
            'employees.view', 'employees.update', 'custom_fields.access_sensitive', 'custom_fields.access_confidential',
        );
        $show = $this->actingAs($everyTierExceptRestricted)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.executive_compensation_note');
        $this->actingAs($everyTierExceptRestricted)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['executive_compensation_note' => 'overwritten'],
        ])->assertForbidden();
    }

    // 10: user without employees.view cannot read employee custom fields.
    public function test_user_without_employees_view_cannot_read_custom_field_values(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $this->textField($tenant, $owner);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $noPermUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($noPermUser)->getJson($this->url($tenant, "employees/{$employee->id}"))->assertForbidden();
    }

    // 11: user without employees.update cannot write employee custom fields.
    public function test_user_without_employees_update_cannot_write_custom_field_values(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $this->textField($tenant, $owner);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $viewOnlyUser = $this->userWithPermissions($tenant, 'employees.view');
        $this->actingAs($viewOnlyUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Small'],
        ])->assertForbidden();
    }

    // 12: direct key submission cannot bypass field-level access.
    public function test_direct_field_key_submission_cannot_bypass_tier_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_restricted');
        $this->textField($tenant, $owner, ['field_key' => 'executive_compensation_note', 'sensitivity' => 'restricted']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $noTierUser = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $this->actingAs($noTierUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['executive_compensation_note' => 'attempted bypass'],
        ])->assertForbidden();
        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'attempted bypass']);
    }

    // 13: tenant isolation.
    public function test_tenant_isolation_for_employee_fields_and_values(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $fieldB = $this->textField($tenantB, $userB);
        $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id]);

        CustomFieldValue::query()->create([
            'tenant_id' => $tenantB->id,
            'entity_type' => 'employee',
            'entity_id' => $employeeB->id,
            'custom_field_definition_id' => $fieldB->id,
            'value_text' => 'Tenant B secret uniform size',
        ]);

        $adminA = $this->userWithPermissions($tenantA, 'custom_fields.view', 'employees.view');
        $this->actingAs($adminA)->getJson($this->url($tenantA, 'custom-fields/employee'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($adminA)->getJson($this->url($tenantA, "employees/{$employeeB->id}"))->assertNotFound();
    }

    // 14: wrong entity_type rejected.
    public function test_unsupported_entity_type_still_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/not_a_real_entity'))->assertStatus(422);
    }

    // 15: recruitment_applicant/job_application fields rejected through employee payload.
    public function test_recruitment_fields_rejected_via_employee_payload(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'job_application',
            'field_key' => 'priority_tier', 'label' => 'Priority Tier', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        foreach (['visa_status', 'priority_tier'] as $key) {
            $response = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
                'custom_field_values' => [$key => 'x'],
            ]);
            $response->assertUnprocessable();
        }
        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $employee->id]);
    }

    // 16: employee fields rejected through recruitment payloads (both directions).
    public function test_employee_field_rejected_via_recruitment_payloads(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user);

        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);
        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_job_id' => $job->id,
            'recruitment_applicant_id' => $applicant->id,
        ]);

        $viaApplicant = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ]);
        $viaApplicant->assertUnprocessable();

        $viaApplication = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['uniform_size' => 'Large'],
        ]);
        $viaApplication->assertUnprocessable();

        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'Large']);
    }

    // 17: disabled employee field preserves values but is hidden/edit-blocked.
    public function test_disabled_employee_field_preserves_value_but_is_not_editable(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.view', 'custom_fields.manage');
        $field = $this->textField($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Medium'],
        ])->assertOk();

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$field->id}"), ['status' => 'inactive'])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.uniform_size');
        $this->assertDatabaseHas('custom_field_values', ['custom_field_definition_id' => $field->id, 'value_text' => 'Medium']);

        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Small'],
        ])->assertUnprocessable();
    }

    // 18: audit masking works for employee custom fields.
    public function test_sensitive_employee_field_value_is_masked_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $this->textField($tenant, $user, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'call spouse first'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('***MASKED***', $log->new_values['value']);
        $this->assertSame('employee', $log->metadata['entity_type']);
    }

    // 19: normal values audit safely.
    public function test_normal_employee_field_value_is_audited_safely(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $this->textField($tenant, $user);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('Large', $log->new_values['value']);
    }

    // 20: custom-fields/employee works when Recruitment module is disabled.
    public function test_employee_custom_fields_work_when_recruitment_module_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantModuleAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => 'recruitment'],
            ['enabled' => false],
        );
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/employee'))->assertOk();
        $create = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/employee'), [
            'field_key' => 'uniform_size', 'label' => 'Uniform Size', 'field_type' => 'text',
        ]);
        $create->assertCreated();

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ])->assertOk();
    }

    // 21: custom-fields/job_application is blocked when Recruitment module is disabled.
    public function test_job_application_custom_fields_blocked_when_recruitment_module_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantModuleAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => 'recruitment'],
            ['enabled' => false],
        );
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/job_application'))->assertForbidden();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/job_application'), [
            'field_key' => 'priority_tier', 'label' => 'Priority Tier', 'field_type' => 'text',
        ])->assertForbidden();
        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'))->assertForbidden();
    }

    // Decision 6: no non-Tenant-Admin/non-HR-Manager role receives custom-field
    // tier access by default. HR Director holds zero employees.* permissions
    // today (confirmed by reading RoleSeeder directly), so it cannot reach an
    // employee record at all — testing it here would be vacuous. An ad-hoc
    // HR-Officer-style role (parent permissions, no tier permission) is the
    // accurate stand-in, per your explicit approval.
    public function test_no_ad_hoc_role_receives_tier_access_by_default(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $this->textField($tenant, $owner, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($owner)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'secret'],
        ])->assertOk();

        $adHocRole = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $show = $this->actingAs($adHocRole)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.emergency_contact_note');
    }
}
