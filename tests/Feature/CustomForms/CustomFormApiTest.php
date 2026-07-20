<?php

namespace Tests\Feature\CustomForms;

use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\CustomForm;
use App\Models\CustomFormField;
use App\Models\CustomFormSection;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModuleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 52 — Custom Forms Foundation. Employee is the first (and
 * only, for this checkpoint) surface. A form is metadata only — it
 * never introduces a second read/write path for values: submitting
 * still means PATCH /employees/{employee} with custom_field_values,
 * validated by CustomFieldValueValidator and saved by
 * CustomFieldValueService exactly as before Checkpoint 52 existed.
 *
 * Per the user's explicit non-negotiable rule: every check here targets
 * the API directly, simulating a forged request that skips whatever a
 * real frontend would have prevented (a hidden button, a filtered
 * picker, a disabled input) — the backend must independently reject it
 * regardless of what any UI would or wouldn't have shown.
 */
class CustomFormApiTest extends TestCase
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

    protected function employeeField(Tenant $tenant, User $actor, array $overrides = []): CustomFieldDefinition
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

    // 1: Tenant Admin can create a custom form.
    public function test_tenant_admin_can_create_custom_form(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'employee_additional_info',
            'name' => 'Employee Additional Information',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.entity_type', 'employee');
        $response->assertJsonPath('data.form_key', 'employee_additional_info');
        $this->assertDatabaseHas('custom_forms', ['tenant_id' => $tenant->id, 'form_key' => 'employee_additional_info']);
    }

    // 2: HR Manager can view but cannot manage forms.
    public function test_hr_manager_can_view_but_cannot_manage_forms(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'))->assertOk();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'attempt', 'name' => 'Attempt',
        ])->assertForbidden();
    }

    // Direct API bypass: a user with zero custom_forms.* permission at all.
    public function test_user_without_any_custom_forms_permission_is_blocked_both_ways(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'))->assertForbidden();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'attempt', 'name' => 'Attempt',
        ])->assertForbidden();
    }

    // 3: form_key validation (format).
    public function test_form_key_format_is_validated(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');

        foreach (['Invalid Key', '1starts_with_digit', 'has-dashes', str_repeat('a', 61)] as $badKey) {
            $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
                'form_key' => $badKey, 'name' => 'X',
            ]);
            $response->assertUnprocessable();
        }
    }

    // 4: form_key immutability — never accepted on update.
    public function test_form_key_is_immutable_on_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $create = $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'original_key', 'name' => 'Original',
        ]);
        $formId = $create->json('data.id');

        // Direct API bypass: forge a request that includes form_key anyway.
        $this->actingAs($user)->patchJson($this->url($tenant, "custom-forms/{$formId}"), [
            'form_key' => 'renamed_key', 'name' => 'Renamed',
        ])->assertOk();

        $this->assertDatabaseHas('custom_forms', ['id' => $formId, 'form_key' => 'original_key']);
        $this->assertDatabaseMissing('custom_forms', ['id' => $formId, 'form_key' => 'renamed_key']);
    }

    // 5: duplicate form_key rejected.
    public function test_duplicate_form_key_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'dup_key', 'name' => 'First',
        ])->assertCreated();

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'dup_key', 'name' => 'Second',
        ]);
        $response->assertUnprocessable();
    }

    // 6: section_key validation (format).
    public function test_section_key_format_is_validated(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $form = $this->createForm($tenant, $user);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "custom-forms/{$form->id}/sections"), [
            'section_key' => 'Bad Key!', 'title' => 'X',
        ]);
        $response->assertUnprocessable();
    }

    // 7: section_key immutability.
    public function test_section_key_is_immutable_on_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $form = $this->createForm($tenant, $user);
        $section = $this->createSection($tenant, $user, $form, ['section_key' => 'original_section']);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-form-sections/{$section->id}"), [
            'section_key' => 'renamed_section', 'title' => 'Renamed',
        ])->assertOk();

        $this->assertDatabaseHas('custom_form_sections', ['id' => $section->id, 'section_key' => 'original_section']);
    }

    // 8: duplicate section_key rejected (per form).
    public function test_duplicate_section_key_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $form = $this->createForm($tenant, $user);
        $this->createSection($tenant, $user, $form, ['section_key' => 'dup_section']);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "custom-forms/{$form->id}/sections"), [
            'section_key' => 'dup_section', 'title' => 'Second',
        ]);
        $response->assertUnprocessable();
    }

    // 9: unsupported entity_type returns 422.
    public function test_unsupported_entity_type_returns_422(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/not_a_real_entity'))->assertStatus(422);
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/not_a_real_entity'), [
            'form_key' => 'x', 'name' => 'X',
        ])->assertStatus(422);
    }

    // 10/11: module gating — employee forms unaffected, recruitment forms blocked.
    public function test_employee_form_works_when_recruitment_module_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantModuleAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => 'recruitment'],
            ['enabled' => false],
        );
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'))->assertOk();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'employee_form', 'name' => 'Employee Form',
        ])->assertCreated();
    }

    public function test_recruitment_form_blocked_when_recruitment_module_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantModuleAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => 'recruitment'],
            ['enabled' => false],
        );
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/recruitment_applicant'))->assertForbidden();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-forms/job_application'), [
            'form_key' => 'x', 'name' => 'X',
        ])->assertForbidden();
    }

    // 12: field from wrong entity rejected — direct API bypass attempt.
    public function test_field_from_wrong_entity_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $form = $this->createForm($tenant, $user);
        $section = $this->createSection($tenant, $user, $form);

        $recruitmentField = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson($this->url($tenant, "custom-form-sections/{$section->id}/fields"), [
            'custom_field_definition_id' => $recruitmentField->id,
        ]);
        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_form_fields', ['custom_field_definition_id' => $recruitmentField->id]);
    }

    // 13: cross-tenant field added to form rejected — direct API bypass attempt.
    public function test_cross_tenant_field_added_to_form_rejected(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'custom_forms.view', 'custom_forms.manage');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $formA = $this->createForm($tenantA, $userA);
        $sectionA = $this->createSection($tenantA, $userA, $formA);

        $fieldB = $this->employeeField($tenantB, $userB, ['field_key' => 'tenant_b_field']);

        $response = $this->actingAs($userA)->postJson($this->url($tenantA, "custom-form-sections/{$sectionA->id}/fields"), [
            'custom_field_definition_id' => $fieldB->id,
        ]);
        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_form_fields', ['custom_field_definition_id' => $fieldB->id]);
    }

    // 14: disabled custom field is omitted from the rendered form structure.
    public function test_disabled_custom_field_is_omitted_from_form_structure(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $user);
        $form = $this->createForm($tenant, $user);
        $section = $this->createSection($tenant, $user, $form);
        $this->addField($tenant, $user, $section, $field);

        // Disable the underlying custom field (via the existing custom-fields endpoint).
        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$field->id}"), ['status' => 'inactive'])->assertOk();

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'));
        $response->assertOk();
        $sections = collect($response->json('data.0.sections'));
        $this->assertTrue($sections->first()['fields'] === []);
    }

    // 15: form renders only can_view fields.
    public function test_form_structure_only_includes_can_view_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update', 'custom_fields.access_restricted');
        $restrictedField = $this->employeeField($tenant, $admin, ['field_key' => 'executive_note', 'sensitivity' => 'restricted']);
        $form = $this->createForm($tenant, $admin);
        $section = $this->createSection($tenant, $admin, $form);
        $this->addField($tenant, $admin, $section, $restrictedField);

        // Tenant Admin (full tier access) sees the field.
        $adminView = $this->actingAs($admin)->getJson($this->url($tenant, 'custom-forms/employee'));
        $adminSections = collect($adminView->json('data.0.sections'));
        $this->assertCount(1, $adminSections->first()['fields']);

        // A viewer with custom_forms.view but no restricted-tier access does not.
        $noTierViewer = $this->userWithPermissions($tenant, 'custom_forms.view', 'employees.view', 'employees.update');
        $viewerResponse = $this->actingAs($noTierViewer)->getJson($this->url($tenant, 'custom-forms/employee'));
        $viewerSections = collect($viewerResponse->json('data.0.sections'));
        $this->assertCount(0, $viewerSections->first()['fields']);
    }

    // 16/17: form submission still goes through the existing entity endpoint,
    // still respects can_edit — direct API bypass attempt on a field the
    // form "shows" but the actor cannot edit.
    public function test_form_field_write_through_entity_endpoint_rejects_fields_actor_cannot_edit(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $sensitiveField = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $form = $this->createForm($tenant, $admin);
        $section = $this->createSection($tenant, $admin, $form);
        $this->addField($tenant, $admin, $section, $sensitiveField);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        // A user who can view/update the employee but lacks the tier
        // permission the form's own field requires — a forged request
        // straight to the entity endpoint, bypassing whatever the form UI
        // would have hidden.
        $noTierUser = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $response = $this->actingAs($noTierUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'forged bypass attempt'],
        ]);
        $response->assertForbidden();
        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'forged bypass attempt']);
    }

    // 18: values still flow through CustomFieldValueService/Validator — proven
    // by the existing validation rules still applying (e.g. unknown key 422).
    public function test_form_assigned_field_values_still_validated_and_saved_normally(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $user);
        $form = $this->createForm($tenant, $user);
        $section = $this->createSection($tenant, $user, $form);
        $this->addField($tenant, $user, $section, $field);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ]);
        $response->assertOk();
        $response->assertJsonPath('data.custom_field_values.uniform_size', 'Large');

        // Unknown field key still rejected exactly as before forms existed.
        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['not_a_real_field' => 'x'],
        ])->assertUnprocessable();
    }

    // 19: sensitive/confidential/restricted fields inside a form still obey tier access.
    public function test_confidential_field_in_form_still_requires_access_confidential(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update', 'custom_fields.access_confidential');
        $confidentialField = $this->employeeField($tenant, $admin, ['field_key' => 'background_check_note', 'sensitivity' => 'confidential']);
        $form = $this->createForm($tenant, $admin);
        $section = $this->createSection($tenant, $admin, $form);
        $this->addField($tenant, $admin, $section, $confidentialField);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['background_check_note' => 'clean'],
        ])->assertOk();

        $sensitiveOnlyUser = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $this->actingAs($sensitiveOnlyUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['background_check_note' => 'forged'],
        ])->assertForbidden();
    }

    // 20: audit masking remains custom_field.value_updated, unaffected by forms.
    public function test_audit_masking_remains_custom_field_value_updated(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $sensitiveField = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $form = $this->createForm($tenant, $admin);
        $section = $this->createSection($tenant, $admin, $form);
        $this->addField($tenant, $admin, $section, $sensitiveField);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'call spouse'],
        ])->assertOk();

        $valueLog = AuditLog::query()->where('action', 'custom_field.value_updated')->firstOrFail();
        $this->assertSame('***MASKED***', $valueLog->new_values['value']);

        // No duplicate form-submission value event exists.
        $this->assertDatabaseMissing('audit_logs', ['action' => 'custom_form.value_updated']);
    }

    // 21: form configuration changes emit custom_form.* events.
    public function test_form_configuration_changes_emit_custom_form_events(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $user);

        $form = $this->createForm($tenant, $user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.created', 'auditable_id' => $form->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-forms/{$form->id}"), ['name' => 'Renamed Form'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.updated', 'auditable_id' => $form->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-forms/{$form->id}"), ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.disabled', 'auditable_id' => $form->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-forms/{$form->id}"), ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.enabled', 'auditable_id' => $form->id]);

        $section = $this->createSection($tenant, $user, $form);
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.section_added', 'auditable_id' => $section->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-form-sections/{$section->id}"), ['title' => 'Renamed'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.section_updated', 'auditable_id' => $section->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-form-sections/{$section->id}"), ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.section_removed', 'auditable_id' => $section->id]);

        $field->status = 'active';
        $field->save();
        $section->status = 'active';
        $section->save();
        $formField = $this->addField($tenant, $user, $section, $field);
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.field_added', 'auditable_id' => $formField->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-form-fields/{$formField->id}"), ['help_text' => 'Pick a size'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.field_updated', 'auditable_id' => $formField->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-form-fields/{$formField->id}"), ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_form.field_removed', 'auditable_id' => $formField->id]);
    }

    // 22: tenant isolation.
    public function test_tenant_isolation_for_forms(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = $this->userWithPermissions($tenantB, 'custom_forms.view', 'custom_forms.manage');
        $this->createForm($tenantB, $userB);

        $adminA = $this->userWithPermissions($tenantA, 'custom_forms.view');
        $response = $this->actingAs($adminA)->getJson($this->url($tenantA, 'custom-forms/employee'));
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // 23: cross-tenant form access blocked — direct API bypass attempt.
    public function test_cross_tenant_form_access_blocked(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = $this->userWithPermissions($tenantB, 'custom_forms.view', 'custom_forms.manage');
        $formB = $this->createForm($tenantB, $userB);

        $adminA = $this->userWithPermissions($tenantA, 'custom_forms.view', 'custom_forms.manage');
        $this->actingAs($adminA)->patchJson($this->url($tenantA, "custom-forms/{$formB->id}"), ['name' => 'Hijacked'])->assertNotFound();
    }

    // 24: disabled form not returned by the entity-page-relevant portion of
    // list endpoint (still returned raw for Settings management — the
    // frontend, like CustomFieldsCard, filters to status === active).
    public function test_disabled_form_status_is_reported_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_forms.view', 'custom_forms.manage');
        $form = $this->createForm($tenant, $user);
        $this->actingAs($user)->patchJson($this->url($tenant, "custom-forms/{$form->id}"), ['status' => 'inactive'])->assertOk();

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'));
        $response->assertOk();
        $this->assertSame('inactive', $response->json('data.0.status'));
    }

    protected function createForm(Tenant $tenant, User $actor, array $overrides = []): CustomForm
    {
        $response = $this->actingAs($actor)->postJson($this->url($tenant, 'custom-forms/employee'), array_merge([
            'form_key' => 'employee_additional_info',
            'name' => 'Employee Additional Information',
        ], $overrides));

        return CustomForm::query()->findOrFail($response->json('data.id'));
    }

    protected function createSection(Tenant $tenant, User $actor, CustomForm $form, array $overrides = []): CustomFormSection
    {
        $response = $this->actingAs($actor)->postJson($this->url($tenant, "custom-forms/{$form->id}/sections"), array_merge([
            'section_key' => 'general',
            'title' => 'General',
        ], $overrides));

        return CustomFormSection::query()->findOrFail($response->json('data.id'));
    }

    protected function addField(Tenant $tenant, User $actor, CustomFormSection $section, CustomFieldDefinition $field): CustomFormField
    {
        $response = $this->actingAs($actor)->postJson($this->url($tenant, "custom-form-sections/{$section->id}/fields"), [
            'custom_field_definition_id' => $field->id,
        ]);

        return CustomFormField::query()->findOrFail($response->json('data.id'));
    }
}
