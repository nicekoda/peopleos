<?php

namespace Tests\Feature\CustomFields;

use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\Permission;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 50 — field-level visibility and sensitive field access.
 * Fixed, platform-defined tier permissions (custom_fields.access_sensitive/
 * confidential/restricted), enforced in CustomFieldValueService, not in
 * React or in the API Resource alone. No configurable per-tenant rules
 * table exists yet (decision 1/2) — that stays a future checkpoint.
 */
class CustomFieldVisibilityApiTest extends TestCase
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

    protected function fieldWithSensitivity(Tenant $tenant, User $actor, string $sensitivity, array $overrides = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'entity_type' => 'recruitment_applicant',
            'field_key' => $sensitivity.'_field',
            'label' => ucfirst($sensitivity).' Field',
            'field_type' => 'text',
            'sensitivity' => $sensitivity,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ], $overrides));
    }

    // 1: normal field read is unaffected by the new tier logic.
    public function test_normal_field_read_is_unchanged(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->fieldWithSensitivity($tenant, $user, 'normal');
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['normal_field' => 'plain value'],
        ])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();
        $show->assertJsonPath('data.applicant.custom_field_values.normal_field', 'plain value');
    }

    // 2: normal field write is unaffected by the new tier logic.
    public function test_normal_field_write_is_unchanged(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->fieldWithSensitivity($tenant, $user, 'normal');
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['normal_field' => 'updated'],
        ]);
        $response->assertOk();
        $this->assertDatabaseHas('custom_field_values', ['value_text' => 'updated']);
    }

    // 3: a user holding custom_fields.access_sensitive can read and write a sensitive field.
    public function test_sensitive_field_is_visible_and_editable_with_access_sensitive_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive',
        );
        $this->fieldWithSensitivity($tenant, $user, 'sensitive');
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'secret value'],
        ])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonPath('data.applicant.custom_field_values.sensitive_field', 'secret value');
    }

    // 4: a user without custom_fields.access_sensitive cannot read or write a sensitive field.
    public function test_sensitive_field_is_hidden_and_blocked_without_access_sensitive_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive',
        );
        $this->fieldWithSensitivity($tenant, $owner, 'sensitive');
        $application = $this->applicationFor($tenant);

        $this->actingAs($owner)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'secret value'],
        ])->assertOk();

        $noTierUser = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');

        // Read: value is silently omitted, not exposed and not errored.
        $show = $this->actingAs($noTierUser)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();
        $show->assertJsonMissingPath('data.applicant.custom_field_values.sensitive_field');

        // Write: rejected with 403, and the existing value is untouched.
        $write = $this->actingAs($noTierUser)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'overwritten'],
        ]);
        $write->assertForbidden();
        $this->assertDatabaseHas('custom_field_values', ['value_text' => 'secret value']);
        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'overwritten']);
    }

    // 5: confidential field requires custom_fields.access_confidential specifically — access_sensitive is not enough.
    public function test_confidential_field_requires_its_own_tier_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_confidential',
        );
        $this->fieldWithSensitivity($tenant, $owner, 'confidential');
        $application = $this->applicationFor($tenant);

        $this->actingAs($owner)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['confidential_field' => 'top secret'],
        ])->assertOk();

        // No implied hierarchy: holding access_sensitive does NOT grant confidential access.
        $sensitiveOnlyUser = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive',
        );
        $show = $this->actingAs($sensitiveOnlyUser)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonMissingPath('data.applicant.custom_field_values.confidential_field');
        $this->actingAs($sensitiveOnlyUser)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['confidential_field' => 'overwritten'],
        ])->assertForbidden();
    }

    // 6: restricted field requires custom_fields.access_restricted specifically.
    public function test_restricted_field_requires_its_own_tier_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_restricted',
        );
        $this->fieldWithSensitivity($tenant, $owner, 'restricted');
        $application = $this->applicationFor($tenant);

        $this->actingAs($owner)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['restricted_field' => 'the most secret'],
        ])->assertOk();

        $everyTierExceptRestricted = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update',
            'custom_fields.access_sensitive', 'custom_fields.access_confidential',
        );
        $show = $this->actingAs($everyTierExceptRestricted)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonMissingPath('data.applicant.custom_field_values.restricted_field');
        $this->actingAs($everyTierExceptRestricted)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['restricted_field' => 'overwritten'],
        ])->assertForbidden();
    }

    // 7: bypass attempt — submitting the field key directly does not grant access.
    public function test_direct_field_key_submission_cannot_bypass_tier_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_restricted',
        );
        $this->fieldWithSensitivity($tenant, $owner, 'restricted');
        $application = $this->applicationFor($tenant);

        $noTierUser = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');

        // There is no alternate payload shape or key that grants write access
        // to a field the actor lacks tier permission for.
        $this->actingAs($noTierUser)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['restricted_field' => 'attempted bypass'],
        ])->assertForbidden();
        $this->assertDatabaseMissing('custom_field_values', ['value_text' => 'attempted bypass']);
    }

    // 8: parent entity permission is still required before field-tier access matters.
    public function test_parent_permission_is_still_required_regardless_of_tier_access(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive',
        );
        $this->fieldWithSensitivity($tenant, $owner, 'sensitive');
        $application = $this->applicationFor($tenant);

        // Holds every tier permission but no job_applications.* permission at all.
        $noParentPermUser = $this->userWithPermissions(
            $tenant,
            'custom_fields.access_sensitive', 'custom_fields.access_confidential', 'custom_fields.access_restricted',
        );

        $this->actingAs($noParentPermUser)->getJson($this->url($tenant, "job-applications/{$application->id}"))->assertForbidden();
        $this->actingAs($noParentPermUser)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'x'],
        ])->assertForbidden();
    }

    // 9: definitions endpoint returns accurate can_view/can_edit per tier + parent permission.
    public function test_definitions_endpoint_reports_correct_can_view_and_can_edit_flags(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'job_applications.view', 'job_applications.update');
        $this->fieldWithSensitivity($tenant, $admin, 'normal', ['field_key' => 'normal_field']);
        $this->fieldWithSensitivity($tenant, $admin, 'sensitive', ['field_key' => 'sensitive_field']);

        // Viewer: parent view only (no update), sensitive tier granted.
        $viewer = $this->userWithPermissions($tenant, 'custom_fields.view', 'job_applications.view', 'custom_fields.access_sensitive');

        $response = $this->actingAs($viewer)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'));
        $response->assertOk();

        $byKey = collect($response->json('data'))->keyBy('field_key');
        $this->assertTrue($byKey['normal_field']['can_view']);
        $this->assertFalse($byKey['normal_field']['can_edit']);
        $this->assertTrue($byKey['sensitive_field']['can_view']);
        $this->assertFalse($byKey['sensitive_field']['can_edit']);

        // Same viewer, but without the sensitive tier permission: sensitive
        // definition itself is still listed (it's metadata, not a value),
        // but can_view/can_edit both go false because the tier gate fails.
        $noTierViewer = $this->userWithPermissions($tenant, 'custom_fields.view', 'job_applications.view', 'job_applications.update');
        $response2 = $this->actingAs($noTierViewer)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'));
        $byKey2 = collect($response2->json('data'))->keyBy('field_key');
        $this->assertTrue($byKey2['normal_field']['can_edit']);
        $this->assertFalse($byKey2['sensitive_field']['can_view']);
        $this->assertFalse($byKey2['sensitive_field']['can_edit']);
    }

    // 10: tenant isolation still holds with tier permissions in play.
    public function test_tenant_isolation_holds_for_sensitive_field_values(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $ownerB = $this->userWithPermissions(
            $tenantB,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_restricted',
        );
        $this->fieldWithSensitivity($tenantB, $ownerB, 'restricted');
        $applicationB = $this->applicationFor($tenantB);

        $this->actingAs($ownerB)->patchJson($this->url($tenantB, "job-applications/{$applicationB->id}"), [
            'custom_field_values' => ['restricted_field' => 'tenant b secret'],
        ])->assertOk();

        // Tenant A admin, even with every tier permission, cannot reach
        // tenant B's application at all — BelongsToTenant scoping wins first.
        $adminA = $this->userWithPermissions(
            $tenantA,
            'job_applications.view', 'job_applications.update',
            'custom_fields.access_sensitive', 'custom_fields.access_confidential', 'custom_fields.access_restricted',
        );
        $this->actingAs($adminA)->getJson($this->url($tenantA, "job-applications/{$applicationB->id}"))->assertNotFound();
    }

    // 11: disabled fields remain hidden/edit-blocked exactly as before, independent of tier access.
    public function test_disabled_field_interaction_with_tier_access(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive', 'custom_fields.manage', 'custom_fields.view',
        );
        $field = $this->fieldWithSensitivity($tenant, $user, 'sensitive');
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'value before disable'],
        ])->assertOk();

        $field->status = 'inactive';
        $field->save();

        // Even with full tier access, a disabled field is hidden and un-writable —
        // the disabled check and the tier check are independent gates, both must pass.
        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonMissingPath('data.applicant.custom_field_values.sensitive_field');

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'attempted while disabled'],
        ])->assertUnprocessable();
    }

    // 12: audit masking remains unchanged — masked regardless of the actor's own tier access.
    public function test_audit_masking_is_unaffected_by_actors_own_tier_access(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_restricted',
        );
        $this->fieldWithSensitivity($tenant, $user, 'restricted');
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['restricted_field' => 'top secret payload'],
        ])->assertOk();

        // Masked in the audit log even though the actor who wrote it has full access to the field.
        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->firstOrFail();
        $this->assertSame('***MASKED***', $log->new_values['value']);
        $this->assertStringNotContainsString('top secret payload', json_encode($log->new_values));
    }

    // 13: normal-sensitivity values still audit safely (unmasked) under the new logic.
    public function test_normal_field_value_still_audits_safely(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->fieldWithSensitivity($tenant, $user, 'normal');
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['normal_field' => 'plain audit value'],
        ])->assertOk();

        $log = AuditLog::query()->where('action', 'custom_field.value_updated')->firstOrFail();
        $this->assertSame('plain audit value', $log->new_values['value']);
    }

    // 14: no read-denial audit events are emitted for a hidden sensitive field (decision 13).
    public function test_no_read_denial_audit_event_is_emitted_for_a_hidden_field(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->userWithPermissions(
            $tenant,
            'job_applications.view', 'job_applications.update', 'custom_fields.access_sensitive',
        );
        $this->fieldWithSensitivity($tenant, $owner, 'sensitive');
        $application = $this->applicationFor($tenant);

        $this->actingAs($owner)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'secret'],
        ])->assertOk();

        $noTierUser = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        $this->actingAs($noTierUser)->getJson($this->url($tenant, "job-applications/{$application->id}"))->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'custom_field.value_denied']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'field_visibility_rule.denied']);
    }

    // 15: Checkpoint 48 (recruitment_applicant) behaviour does not regress for a normal field.
    public function test_checkpoint_48_recruitment_applicant_normal_field_does_not_regress(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status',
            'label' => 'Visa Status',
            'field_type' => 'text',
            'sensitivity' => 'normal',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['visa_status' => 'Work Permit'],
        ])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonPath('data.applicant.custom_field_values.visa_status', 'Work Permit');
    }

    // 16: Checkpoint 49 (job_application) behaviour does not regress for a normal field.
    public function test_checkpoint_49_job_application_normal_field_does_not_regress(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'job_applications.view', 'job_applications.update');
        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id,
            'entity_type' => 'job_application',
            'field_key' => 'priority_tier',
            'label' => 'Priority Tier',
            'field_type' => 'text',
            'sensitivity' => 'normal',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $application = $this->applicationFor($tenant);

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'application_custom_field_values' => ['priority_tier' => 'high'],
        ])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonPath('data.custom_field_values.priority_tier', 'high');
    }

    // 17/18/19: seeded role defaults — HR Manager gets access_sensitive, HR Director
    // and Tenant Admin behave per decision 6/1 (Tenant Admin holds every non-platform
    // permission via its blanket grant; HR Director deliberately gets none of the
    // three new tiers by default in this MVP).
    public function test_seeded_hr_manager_role_has_access_sensitive_but_not_confidential_or_restricted(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();
        $role = Role::query()->where('tenant_id', $tenant->id)->where('name', 'HR Manager')->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        $this->fieldWithSensitivity($tenant, $user, 'sensitive');
        $this->fieldWithSensitivity($tenant, $user, 'confidential');
        $application = $this->applicationFor($tenant);

        $show = $this->actingAs($user)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['sensitive_field' => 'ok'],
        ])->assertOk();

        $this->actingAs($user)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => ['confidential_field' => 'blocked'],
        ])->assertForbidden();
    }

    public function test_seeded_hr_director_role_does_not_get_any_tier_permission_by_default(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();
        $hrDirectorRole = Role::query()->where('tenant_id', $tenant->id)->where('name', 'HR Director')->firstOrFail();
        $director = User::factory()->create(['tenant_id' => $tenant->id]);
        $director->assignRole($hrDirectorRole);

        // Field owner needs a real tier permission to create the value in
        // the first place — use Tenant Admin (blanket grant) for that.
        $tenantAdminRole = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Tenant Admin')->firstOrFail();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole($tenantAdminRole);

        $this->fieldWithSensitivity($tenant, $admin, 'sensitive');
        $this->fieldWithSensitivity($tenant, $admin, 'confidential');
        $this->fieldWithSensitivity($tenant, $admin, 'restricted');
        $application = $this->applicationFor($tenant);

        $this->actingAs($admin)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => [
                'sensitive_field' => 'a',
                'confidential_field' => 'b',
                'restricted_field' => 'c',
            ],
        ])->assertOk();

        // HR Director holds job_applications.view/update (see RoleSeeder) but
        // none of the three tier permissions — every non-normal value must be
        // omitted from the read, and every write attempt must be blocked.
        $show = $this->actingAs($director)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertOk();
        $show->assertJsonMissingPath('data.applicant.custom_field_values.sensitive_field');
        $show->assertJsonMissingPath('data.applicant.custom_field_values.confidential_field');
        $show->assertJsonMissingPath('data.applicant.custom_field_values.restricted_field');

        foreach (['sensitive_field', 'confidential_field', 'restricted_field'] as $key) {
            $this->actingAs($director)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
                'custom_field_values' => [$key => 'director attempt'],
            ])->assertForbidden();
        }
    }

    public function test_seeded_tenant_admin_role_has_every_tier_permission_by_default(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $tenant = Tenant::query()->where('subdomain', 'uesl')->firstOrFail();
        $role = Role::query()->where('tenant_id', $tenant->id)->where('name', 'Tenant Admin')->firstOrFail();
        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole($role);

        $this->fieldWithSensitivity($tenant, $admin, 'sensitive');
        $this->fieldWithSensitivity($tenant, $admin, 'confidential');
        $this->fieldWithSensitivity($tenant, $admin, 'restricted');
        $application = $this->applicationFor($tenant);

        $response = $this->actingAs($admin)->patchJson($this->url($tenant, "job-applications/{$application->id}"), [
            'custom_field_values' => [
                'sensitive_field' => 'a',
                'confidential_field' => 'b',
                'restricted_field' => 'c',
            ],
        ]);
        $response->assertOk();

        $show = $this->actingAs($admin)->getJson($this->url($tenant, "job-applications/{$application->id}"));
        $show->assertJsonPath('data.applicant.custom_field_values.sensitive_field', 'a');
        $show->assertJsonPath('data.applicant.custom_field_values.confidential_field', 'b');
        $show->assertJsonPath('data.applicant.custom_field_values.restricted_field', 'c');
    }
}
