<?php

namespace Tests\Feature\CustomFields;

use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Checkpoint 48 — Custom Fields Foundation, definition management.
 * MVP ships exactly one entity (recruitment_applicant); a future
 * HR-Administrator/implementation-engineer role may eventually get
 * custom_fields.manage without full Tenant Admin rights — today only
 * Tenant Admin has it, a deliberate scope boundary, not an oversight.
 */
class CustomFieldDefinitionApiTest extends TestCase
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

    public function test_tenant_admin_can_create_a_custom_field(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status',
            'label' => 'Visa Status',
            'field_type' => 'text',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.field_key', 'visa_status');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('custom_field_definitions', ['tenant_id' => $tenant->id, 'field_key' => 'visa_status']);
    }

    public function test_view_only_user_can_list_but_cannot_create(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'))->assertOk();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'referral_source',
            'label' => 'Referral Source',
            'field_type' => 'text',
        ])->assertForbidden();
    }

    public function test_user_without_any_permission_is_blocked(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'))->assertForbidden();
    }

    // Decision 3: HR Manager gets view only, never manage, in MVP.
    public function test_hr_manager_role_grant_is_view_only_not_manage(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::factory()->create(['tenant_id' => $tenant->id, 'name' => 'HR Manager']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $viewPermission = Permission::query()->firstOrCreate(['key' => 'custom_fields.view'], ['category' => 'custom_fields', 'is_platform_permission' => false]);
        $role->givePermissionTo($viewPermission);
        $user->assignRole($role);

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'))->assertOk();
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'referral_source',
            'label' => 'Referral Source',
            'field_type' => 'text',
        ])->assertForbidden();
    }

    public function test_unknown_entity_type_is_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/not_a_real_entity'))->assertStatus(422);
        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/employee'), [
            'field_key' => 'x',
            'label' => 'X',
            'field_type' => 'text',
        ])->assertStatus(422);
    }

    public function test_unsupported_field_type_is_rejected_with_422(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'weird_field',
            'label' => 'Weird',
            'field_type' => 'rich_text_html',
        ])->assertStatus(422);
    }

    // Decision 7: field key format — lowercase snake_case, starts with a
    // letter, letters/digits/underscores only.
    public function test_field_key_format_is_enforced(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        foreach (['Visa Status', 'visa-status', '1visa', 'visa$status', '<script>', str_repeat('a', 61)] as $badKey) {
            $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
                'field_key' => $badKey,
                'label' => 'Test',
                'field_type' => 'text',
            ])->assertStatus(422);
        }

        $this->assertDatabaseCount('custom_field_definitions', 0);
    }

    // Decision 8: reserved keys — real columns and dangerous generic names.
    public function test_reserved_field_keys_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        foreach (['first_name', 'email', 'status', 'stage', 'tenant_id', 'password', 'is_platform_admin'] as $reserved) {
            $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
                'field_key' => $reserved,
                'label' => 'Test',
                'field_type' => 'text',
            ])->assertStatus(422);
        }
    }

    public function test_field_key_must_be_unique_per_tenant_and_entity(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'referral_source', 'label' => 'Referral Source', 'field_type' => 'text',
        ])->assertCreated();

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'referral_source', 'label' => 'Referral Source Again', 'field_type' => 'text',
        ])->assertStatus(422);
    }

    // Decision 6: max 50 custom fields per tenant per entity.
    public function test_max_fields_per_tenant_and_entity_is_enforced(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        for ($i = 0; $i < 50; $i++) {
            CustomFieldDefinition::query()->create([
                'tenant_id' => $tenant->id,
                'entity_type' => 'recruitment_applicant',
                'field_key' => "field_{$i}",
                'label' => "Field {$i}",
                'field_type' => 'text',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'one_too_many', 'label' => 'One Too Many', 'field_type' => 'text',
        ])->assertStatus(422);
    }

    /**
     * Regression test — found live during the Checkpoint 48 smoke test:
     * the definition row was inserted before options/default-value
     * validation ran, with no transaction wrapping the whole create(),
     * so a 422 for a bad default value (or a bad option key) still left
     * a real, active, unusable-from-the-request's-perspective definition
     * row behind. Fixed by wrapping the entire create() body in
     * DB::transaction().
     */
    public function test_failed_creation_leaves_no_orphaned_definition_row(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'orphan_check_email', 'label' => 'Orphan Check', 'field_type' => 'email',
            'default_value' => 'not-an-email',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('custom_field_definitions', ['field_key' => 'orphan_check_email']);

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'orphan_check_select', 'label' => 'Orphan Check Select', 'field_type' => 'single_select',
            'options' => [['option_key' => 'Bad Key!', 'label' => 'Bad']],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('custom_field_definitions', ['field_key' => 'orphan_check_select']);
    }

    public function test_select_field_requires_at_least_one_option(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'single_select',
        ])->assertStatus(422);
    }

    public function test_creates_a_select_field_with_options(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status',
            'label' => 'Visa Status',
            'field_type' => 'single_select',
            'options' => [
                ['option_key' => 'citizen', 'label' => 'Citizen'],
                ['option_key' => 'work_permit', 'label' => 'Work Permit'],
            ],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('custom_field_options', ['option_key' => 'citizen']);
        $this->assertDatabaseHas('custom_field_options', ['option_key' => 'work_permit']);
    }

    // Decision 10: default value validated against type/options/rules.
    public function test_invalid_default_value_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'contact_email', 'label' => 'Contact Email', 'field_type' => 'email', 'default_value' => 'not-an-email',
        ])->assertStatus(422);

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'single_select',
            'options' => [['option_key' => 'citizen', 'label' => 'Citizen']],
            'default_value' => 'not_a_real_option',
        ])->assertStatus(422);
    }

    public function test_valid_default_value_is_accepted(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $response = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'contact_email', 'label' => 'Contact Email', 'field_type' => 'email', 'default_value' => 'a@b.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.default_value', 'a@b.com');
    }

    // Decision 4: field_type change blocked once values exist.
    public function test_field_type_cannot_change_once_values_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'job_applications.view', 'job_applications.update');

        $definition = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'notes_field', 'label' => 'Notes', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        CustomFieldValue::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'entity_id' => (string) Str::ulid(), 'custom_field_definition_id' => $definition->id,
            'value_text' => 'some value', 'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definition->id}"), [
            'field_type' => 'number',
        ])->assertStatus(422);
    }

    public function test_field_type_can_change_when_no_values_exist_yet(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $definition = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'notes_field', 'label' => 'Notes', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definition->id}"), [
            'field_type' => 'textarea',
        ])->assertOk();
    }

    // Decision 5: field_key immutable — never accepted on update at all.
    public function test_field_key_cannot_be_changed_via_update(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $definition = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'original_key', 'label' => 'Original', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definition->id}"), [
            'field_key' => 'attempted_new_key',
            'label' => 'Updated Label',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.field_key', 'original_key');
        $this->assertDatabaseHas('custom_field_definitions', ['id' => $definition->id, 'field_key' => 'original_key']);
    }

    // Decision 9: option_key immutable once created.
    public function test_option_key_cannot_be_changed_only_label_and_status(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $create = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'single_select',
            'options' => [['option_key' => 'citizen', 'label' => 'Citizen']],
        ]);
        $definitionId = $create->json('data.id');

        // Resending the same option_key updates the label in place —
        // the key itself never changes since it's the lookup identity.
        $update = $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definitionId}"), [
            'options' => [['option_key' => 'citizen', 'label' => 'Citizen (Updated)']],
        ]);

        $update->assertOk();
        $this->assertDatabaseHas('custom_field_options', ['option_key' => 'citizen', 'label' => 'Citizen (Updated)']);
        $this->assertDatabaseCount('custom_field_options', 1);
    }

    // Decision 9: disabling an option preserves historical values, and
    // rejects it for new writes (covered again at the value layer in
    // CustomFieldValueApiTest).
    public function test_disabling_an_option_does_not_delete_it_and_is_audited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $create = $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'single_select',
            'options' => [['option_key' => 'citizen', 'label' => 'Citizen'], ['option_key' => 'other', 'label' => 'Other']],
        ]);
        $definitionId = $create->json('data.id');

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definitionId}"), [
            'options' => [['option_key' => 'other', 'label' => 'Other', 'status' => 'inactive']],
        ])->assertOk();

        $this->assertDatabaseHas('custom_field_options', ['option_key' => 'other', 'status' => 'inactive']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field.option_removed', 'tenant_id' => $tenant->id]);
    }

    public function test_disabling_and_enabling_a_field_is_audited(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $definition = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'notes_field', 'label' => 'Notes', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definition->id}"), ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field.disabled', 'tenant_id' => $tenant->id]);

        $this->actingAs($user)->patchJson($this->url($tenant, "custom-fields/{$definition->id}"), ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field.enabled', 'tenant_id' => $tenant->id]);
    }

    public function test_tenant_a_cannot_manage_tenant_b_custom_field_definition(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = $this->userWithPermissions($tenantA, 'custom_fields.view', 'custom_fields.manage');

        $definitionB = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenantB->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'other_tenant_field', 'label' => 'Other Tenant Field', 'field_type' => 'text',
        ]);

        $this->actingAs($userA)->patchJson($this->url($tenantA, "custom-fields/{$definitionB->id}"), [
            'label' => 'Hijacked',
        ])->assertNotFound();
    }

    public function test_creating_a_field_writes_a_safe_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        $this->actingAs($user)->postJson($this->url($tenant, 'custom-fields/recruitment_applicant'), [
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'text',
        ])->assertCreated();

        $log = AuditLog::query()->where('action', 'custom_field.created')->firstOrFail();
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame('visa_status', $log->new_values['field_key']);
    }

    public function test_resource_never_exposes_created_by_or_updated_by(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');

        CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'text',
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'));

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString('"created_by"', $body);
        $this->assertStringNotContainsString('"updated_by"', $body);
        $this->assertStringNotContainsString('"tenant_id"', $body);
    }
}
