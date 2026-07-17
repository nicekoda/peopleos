<?php

namespace Tests\Feature\CustomFields;

use App\Enums\ApplicationStage;
use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Permission;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 49 — custom fields for job_application
 * (App\Models\RecruitmentApplication), entity #2. Proves the engine
 * built in Checkpoint 48 is genuinely reusable: no migration, no
 * service, no validator, no audit-event change — only a new
 * CustomFieldEntity case plus this entity's own wiring in
 * JobApplicationController/JobApplicationResource/UpdateJobApplicationRequest.
 */
class CustomFieldJobApplicationValueApiTest extends TestCase
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

    protected function applicationFor(Tenant $tenant): RecruitmentApplication
    {
        $job = RecruitmentJob::factory()->create(['tenant_id' => $tenant->id]);
        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);

        return RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_job_id' => $job->id,
            'recruitment_applicant_id' => $applicant->id,
        ]);
    }

    protected function textField(Tenant $tenant, User $actor, array $overrides = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'entity_type' => 'job_application',
            'field_key' => 'priority_tier',
            'label' => 'Priority Tier',
            'field_type' => 'text',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    // 1: job_application added as a valid CustomFieldEntity case.
    public function test_job_application_is_a_valid_custom_field_entity(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/job_application'))->assertOk();
    }

    // 2: Tenant Admin can create a job_application field.
    public function test_tenant_admin_can_create_a_job_application_field(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/job_application'), [
            'field_key' => 'priority_tier', 'label' => 'Priority Tier', 'field_type' => 'text',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.entity_type', 'job_application');
        $this->assertDatabaseHas('custom_field_definitions', ['entity_type' => 'job_application', 'field_key' => 'priority_tier']);
    }

    // 3: HR Manager can view but not manage definitions.
    public function test_hr_manager_can_view_but_not_manage_job_application_definitions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/job_application'))->assertOk();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/job_application'), [
            'field_key' => 'priority_tier', 'label' => 'Priority Tier', 'field_type' => 'text',
        ])->assertForbidden();
    }

    // 4: values can be set/read through the job application endpoint.
    public function test_setting_and_reading_application_custom_field_values(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $update = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ]);
        $update->assertOk();
        $update->assertJsonPath('data.custom_field_values.priority_tier', 'high');

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();
        $show->assertJsonPath('data.custom_field_values.priority_tier', 'high');
    }

    // 5: user without job_applications.update cannot set application values.
    public function test_user_without_update_permission_cannot_set_application_values(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ])->assertForbidden();
    }

    // 6: user without job_applications.view cannot read application values.
    public function test_user_without_view_permission_cannot_read_application_values(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $admin);
        $application = $this->applicationFor($tenant);

        $noPermUser = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($noPermUser)->getJson($this->url($tenant, "job-applications/{$application->id}"))->assertForbidden();
    }

    // 7: tenant isolation for job_application definitions/values.
    public function test_tenant_isolation_for_job_application_fields_and_values(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $fieldB = $this->textField($tenantB, $userB);
        $applicationB = $this->applicationFor($tenantB);

        CustomFieldValue::query()->create([
            'tenant_id' => $tenantB->id,
            'entity_type' => 'job_application',
            'entity_id' => $applicationB->id,
            'custom_field_definition_id' => $fieldB->id,
            'value_text' => 'Tenant B secret priority',
        ]);

        $adminA = $this->userWithPermissions($tenantA, 'custom_fields.view', 'job_applications.view');
        $this->actingAs($adminA)->getJson($this->url($tenantA, 'custom-fields/job_application'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($adminA)->getJson($this->url($tenantA, "job-applications/{$applicationB->id}"))->assertNotFound();
    }

    // 8: wrong entity_type rejected.
    public function test_unsupported_entity_type_still_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/not_a_real_entity'))->assertStatus(422);
    }

    // 9: recruitment_applicant field cannot be used through application_custom_field_values.
    public function test_recruitment_applicant_field_rejected_via_application_payload_key(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['visa_status' => 'citizen'],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $application->id]);
    }

    // 10: job_application field cannot be used through custom_field_values.
    public function test_job_application_field_rejected_via_applicant_payload_key(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['priority_tier' => 'high'],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $application->recruitment_applicant_id, 'value_text' => 'high']);
    }

    // 11: a field key can validly exist on both entities without collision.
    public function test_same_field_key_on_both_entities_does_not_collide(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');

        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'notes', 'label' => 'Applicant Notes', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'job_application',
            'field_key' => 'notes', 'label' => 'Application Notes', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['notes' => 'Applicant-level note'],
            'application_custom_field_values' => ['notes' => 'Application-level note'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.custom_field_values.notes', 'Application-level note');
        $response->assertJsonPath('data.applicant.custom_field_values.notes', 'Applicant-level note');
    }

    // 12: disabled job_application field preserves values but is not editable.
    public function test_disabled_job_application_field_preserves_value_but_is_not_editable(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update', 'custom_fields.manage', 'custom_fields.view');
        $field = $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ])->assertOk();

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$field->id}"), ['status' => 'inactive'])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.priority_tier');
        $this->assertDatabaseHas('custom_field_values', ['custom_field_definition_id' => $field->id, 'value_text' => 'high']);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'low'],
        ])->assertUnprocessable();
    }

    // 13/14: sensitive values masked, normal values audited safely.
    public function test_sensitive_application_field_value_is_masked_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user, ['field_key' => 'internal_risk_notes', 'sensitivity' => 'confidential']);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['internal_risk_notes' => 'flagged for review'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('***MASKED***', $log->new_values['value']);
        $this->assertSame('job_application', $log->metadata['entity_type']);
    }

    public function test_normal_application_field_value_is_audited_safely(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('high', $log->new_values['value']);
    }

    // 15: conversion does not copy application custom fields to Employee.
    public function test_conversion_does_not_copy_application_custom_fields_to_employee(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'job_applications.convert_to_employee',
        );
        $this->textField($tenant, $user);

        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_applicant_id' => $applicant->id,
            'stage' => ApplicationStage::Hired,
            'ready_for_conversion' => true,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ])->assertOk();

        $convert = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/convert-to-employee"), [
            'employee_number' => 'EMP-CF-001',
            'work_email' => 'converted.cf@example.com',
            'employment_type' => 'full_time',
        ]);
        $convert->assertOk();
        $employeeId = $convert->json('data.converted_employee_id');

        $this->assertNotNull($employeeId);
        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $employeeId]);
        // The application's own value is untouched by the conversion.
        $this->assertDatabaseHas('custom_field_values', ['entity_id' => $application->id, 'value_text' => 'high']);
    }

    // 16: start-onboarding still works unchanged with application custom fields present.
    public function test_start_onboarding_still_works_with_application_custom_fields_present(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'job_applications.convert_to_employee', 'lifecycle.create',
        );
        $this->textField($tenant, $user);

        $applicant = RecruitmentApplicant::factory()->create(['tenant_id' => $tenant->id]);
        $application = RecruitmentApplication::factory()->create([
            'tenant_id' => $tenant->id,
            'recruitment_applicant_id' => $applicant->id,
            'stage' => ApplicationStage::Hired,
            'ready_for_conversion' => true,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ])->assertOk();

        $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/convert-to-employee"), [
            'employee_number' => 'EMP-CF-002',
            'work_email' => 'onboarding.cf@example.com',
            'employment_type' => 'full_time',
        ])->assertOk();

        $onboard = $this->actingAs($user)->postJson($this->url($tenant, "job-applications/{$application->id}/start-onboarding"));
        $onboard->assertOk();
        $this->assertNotNull($onboard->json('data.onboarding_process_id'));
    }
}
