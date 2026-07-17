<?php

namespace Tests\Feature\CustomFields;

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
 * Checkpoint 48 — custom field values, exposed only through the
 * recruitment applicant's own job-application endpoints (decision 17) —
 * no top-level values API exists to test against directly.
 */
class CustomFieldValueApiTest extends TestCase
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
            'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status',
            'label' => 'Visa Status',
            'field_type' => 'text',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    public function test_setting_and_reading_a_value_via_job_application_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $update = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'Work Permit'],
        ]);
        $update->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();
        $show->assertJsonPath('data.applicant.custom_field_values.visa_status', 'Work Permit');
    }

    // Decision 11: required applies on new writes, never retroactively.
    public function test_required_field_does_not_retroactively_break_existing_records(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $application = $this->applicationFor($tenant);

        // A field is made required AFTER the application already exists,
        // with no value ever set — reading/updating unrelated fields must
        // still work.
        $this->textField($tenant, $user, ['is_required' => true]);

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();

        $update = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'cover_letter' => 'An unrelated update.',
        ]);
        $update->assertOk();
    }

    public function test_required_field_validates_on_new_writes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user, ['is_required' => true]);
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => ''],
        ]);

        $response->assertUnprocessable();
    }

    public function test_disabled_field_value_is_preserved_but_not_returned(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $field = $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'Citizen'],
        ])->assertOk();

        $field->status = 'inactive';
        $field->save();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();
        $show->assertJsonMissingPath('data.applicant.custom_field_values.visa_status');

        // The value row itself is untouched in the database.
        $this->assertDatabaseHas('custom_field_values', ['custom_field_definition_id' => $field->id, 'value_text' => 'Citizen']);

        // And it can no longer be written to while disabled.
        $rewrite = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'Something Else'],
        ]);
        $rewrite->assertUnprocessable();
    }

    // Decision 9: disabled option historical display + rejected for new writes.
    public function test_disabled_option_still_displays_but_is_rejected_for_new_writes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update', 'custom_fields.view', 'custom_fields.manage');

        $create = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'single_select',
            'options' => [['option_key' => 'citizen', 'label' => 'Citizen'], ['option_key' => 'legacy_status', 'label' => 'Legacy Status']],
        ]);
        $definitionId = $create->json('data.id');
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'legacy_status'],
        ])->assertOk();

        // Now disable the option a candidate already holds.
        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definitionId}"), [
            'options' => [['option_key' => 'legacy_status', 'label' => 'Legacy Status', 'status' => 'inactive']],
        ])->assertOk();

        // Historical read still shows it safely.
        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonPath('data.applicant.custom_field_values.visa_status', 'legacy_status');

        // But it can no longer be (re-)selected for a new write.
        $rewrite = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'legacy_status'],
        ]);
        $rewrite->assertUnprocessable();
    }

    public function test_multi_select_accepts_a_list_of_valid_option_keys(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update', 'custom_fields.view', 'custom_fields.manage');

        $create = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'skills', 'label' => 'Skills', 'field_type' => 'multi_select',
            'options' => [['option_key' => 'php', 'label' => 'PHP'], ['option_key' => 'react', 'label' => 'React']],
        ]);
        $create->assertCreated();
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['skills' => ['php', 'react']],
        ]);
        $response->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonPath('data.applicant.custom_field_values.skills', ['php', 'react']);
    }

    public function test_multi_select_rejects_an_unknown_option_key(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update', 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'skills', 'label' => 'Skills', 'field_type' => 'multi_select',
            'options' => [['option_key' => 'php', 'label' => 'PHP']],
        ])->assertCreated();
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['skills' => ['php', 'not_a_real_skill']],
        ]);
        $response->assertUnprocessable();
    }

    public function test_unknown_field_key_in_values_payload_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['not_a_real_field' => 'x'],
        ]);
        $response->assertUnprocessable();
    }

    // Decision 21: values cannot be written using another tenant's
    // definition — proven directly at the service layer, since a
    // cross-tenant definition_id could never be reached through the
    // real API (job-applications are tenant-scoped end to end); this
    // confirms the service's own defense-in-depth check, not just the
    // controller boundary.
    public function test_service_rejects_a_value_write_against_another_tenants_definition(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        // Tenant B's definition never becomes "active" from tenant A's
        // point of view, because activeDefinitions() filters by
        // tenant_id — so tenant A submitting B's field_key is
        // indistinguishable from submitting an unknown key.
        $this->textField($tenantB, $userB, ['field_key' => 'tenant_b_only_field']);
        $applicationA = $this->applicationFor($tenantA);
        $adminA = $this->userWithPermissions($tenantA, 'job_applications.view', 'job_applications.update');

        $response = $this->actingAs($adminA)->patchJson($this->url($tenantA, "job-applications/{$applicationA->id}"), [
            'custom_field_values' => ['tenant_b_only_field' => 'leaked'],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_field_values', ['entity_id' => $applicationA->recruitment_applicant_id]);
    }

    public function test_tenant_a_cannot_read_tenant_b_custom_field_values(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $field = $this->textField($tenantB, $userB);
        $applicationB = $this->applicationFor($tenantB);

        CustomFieldValue::query()->create([
            'tenant_id' => $tenantB->id,
            'entity_type' => 'recruitment_applicant',
            'entity_id' => $applicationB->recruitment_applicant_id,
            'custom_field_definition_id' => $field->id,
            'value_text' => 'Tenant B secret',
        ]);

        $userA = $this->userWithPermissions($tenantA, 'job_applications.view');

        // Attempting to read tenant B's application from tenant A's
        // subdomain 404s outright (BelongsToTenant global scope) —
        // proving values can never leak cross-tenant even indirectly.
        $this->actingAs($userA)->getJson($this->url($tenantA, "job-applications/{$applicationB->id}"))->assertNotFound();
    }

    // Decision 14: classification-aware audit masking.
    public function test_sensitive_field_value_is_masked_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        // Checkpoint 50 — writing a `sensitive` field now also requires
        // custom_fields.access_sensitive; masking itself is orthogonal
        // to that and is what this test actually verifies.
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive');
        $this->textField($tenant, $user, ['field_key' => 'medical_notes', 'sensitivity' => 'sensitive']);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['medical_notes' => 'confidential medical detail'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->firstOrFail();
        $this->assertSame('***MASKED***', $log->new_values['value']);
        $this->assertStringNotContainsString('confidential medical detail', json_encode($log->new_values));
    }

    public function test_normal_sensitivity_field_value_is_not_masked_in_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->textField($tenant, $user, ['sensitivity' => 'normal']);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'Citizen'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->firstOrFail();
        $this->assertSame('Citizen', $log->new_values['value']);
    }

    public function test_user_without_job_applications_update_cannot_set_custom_field_values(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view');
        $this->textField($tenant, $user);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'Citizen'],
        ])->assertForbidden();
    }
}
