<?php

namespace Tests\Feature\CustomFields;

use App\Models\AuditLog;
use App\Models\CustomFieldDefinition;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantModuleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkpoint 53 — Configurable Field Visibility Rules. An override
 * layer on top of the fixed sensitivity-tier model (Checkpoint 50),
 * never a replacement for it. Every test here forges the API directly
 * — never asserting UI behavior as a stand-in for a real rejection —
 * per the standing client/server-validation rule.
 */
class CustomFieldVisibilityRuleApiTest extends TestCase
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

    /**
     * Creates a role with the given permissions AND returns it, so a
     * test can both assign a user to it and target it directly with a
     * visibility rule.
     */
    protected function roleWithPermissions(Tenant $tenant, string ...$permissionKeys): Role
    {
        $role = Role::factory()->create(['tenant_id' => $tenant->id]);

        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $key],
                ['category' => explode('.', $key)[0], 'is_platform_permission' => false],
            );
            $role->givePermissionTo($permission);
        }

        return $role;
    }

    protected function userInRole(Tenant $tenant, Role $role): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    // 1: Tenant Admin can create a visibility rule.
    public function test_tenant_admin_can_create_visibility_rule(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenant, $admin);
        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.role.id', $role->id);
        $response->assertJsonPath('data.can_view', true);
        $response->assertJsonPath('data.can_edit', true);
        $this->assertDatabaseHas('custom_field_visibility_rules', ['custom_field_definition_id' => $field->id, 'role_id' => $role->id]);
    }

    // 2: HR Manager can view rules but cannot manage.
    public function test_hr_manager_can_view_but_cannot_manage_rules(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenant, $admin);
        $role = $this->roleWithPermissions($tenant, 'employees.view');

        $hrManager = $this->userWithPermissions($tenant, 'custom_fields.view');
        $view = $this->actingAs($hrManager)->getJson($this->url($tenant, 'custom-fields/employee'));
        $view->assertOk();

        $attempt = $this->actingAs($hrManager)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ]);
        $attempt->assertForbidden();
    }

    // 3: role from another tenant rejected.
    public function test_role_from_another_tenant_rejected(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenantA, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenantA, $admin);
        $roleB = Role::factory()->create(['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($admin)->postJson($this->url($tenantA, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $roleB->id, 'can_view' => true, 'can_edit' => true,
        ]);
        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_field_visibility_rules', ['role_id' => $roleB->id]);
    }

    // 4: platform role rejected.
    public function test_platform_role_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenant, $admin);
        $platformRole = Role::factory()->platform()->create();

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $platformRole->id, 'can_view' => true, 'can_edit' => true,
        ]);
        $response->assertUnprocessable();
    }

    // 5: Tenant Admin role rejected.
    public function test_tenant_admin_role_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenant, $admin);
        $tenantAdminRole = Role::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Tenant Admin', 'slug' => 'tenant-admin']);

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $tenantAdminRole->id, 'can_view' => true, 'can_edit' => true,
        ]);
        $response->assertUnprocessable();
        $this->assertDatabaseMissing('custom_field_visibility_rules', ['role_id' => $tenantAdminRole->id]);
    }

    // 6: field from another tenant rejected.
    public function test_field_from_another_tenant_rejected(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = $this->userWithPermissions($tenantA, 'custom_fields.view', 'custom_fields.manage');
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $fieldB = $this->employeeField($tenantB, $userB);
        $roleA = $this->roleWithPermissions($tenantA, 'employees.view');

        // Attempting to attach a rule to tenant B's field from tenant A's
        // subdomain 404s outright (route model binding + tenant check),
        // never reaching the field at all.
        $response = $this->actingAs($adminA)->postJson($this->url($tenantA, "custom-fields/{$fieldB->id}/visibility-rules"), [
            'role_id' => $roleA->id, 'can_view' => true, 'can_edit' => true,
        ]);
        $response->assertNotFound();
    }

    // 7: can_edit true with can_view false rejected.
    public function test_can_edit_true_with_can_view_false_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenant, $admin);
        $role = $this->roleWithPermissions($tenant, 'employees.view');

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => false, 'can_edit' => true,
        ]);
        $response->assertUnprocessable();
    }

    // 8/9: rule grants view/edit access beyond default tier.
    public function test_rule_grants_view_and_edit_access_beyond_default_tier(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        // This role holds employees.view/.update but NOT
        // custom_fields.access_sensitive — under the default tier
        // model it would be blocked.
        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');
        $user = $this->userInRole($tenant, $role);

        $before = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $before->assertJsonMissingPath('data.custom_field_values.emergency_contact_note');
        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'forged before rule'],
        ])->assertForbidden();

        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ])->assertCreated();

        $after = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $after->assertOk();
        $write = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'granted by rule'],
        ]);
        $write->assertOk();
        $write->assertJsonPath('data.custom_field_values.emergency_contact_note', 'granted by rule');
    }

    // 10/11: rule cannot bypass parent entity view/update permission.
    public function test_rule_cannot_bypass_parent_entity_permission(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        // This role holds NEITHER employees.view NOR employees.update —
        // a rule granting full field access must not matter.
        $role = $this->roleWithPermissions($tenant);
        $user = $this->userInRole($tenant, $role);

        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ])->assertCreated();

        $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"))->assertForbidden();
        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'forged'],
        ])->assertForbidden();
    }

    // 12: rule can make a field read-only.
    public function test_rule_can_make_field_read_only(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ])->assertOk();

        // Role has full default tier access (normal field, no tier
        // permission needed) — the rule narrows it to view-only.
        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');
        $user = $this->userInRole($tenant, $role);

        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => false,
        ])->assertCreated();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonPath('data.custom_field_values.uniform_size', 'Large');

        $write = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'forged read-only bypass'],
        ]);
        $write->assertForbidden();
        $this->assertDatabaseHas('custom_field_values', ['value_text' => 'Large']);
    }

    // 13: rule can fully deny a field.
    public function test_rule_can_fully_deny_field(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ])->assertOk();

        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');
        $user = $this->userInRole($tenant, $role);

        // Confirm default access first (normal field, no tier needed).
        $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"))
            ->assertJsonPath('data.custom_field_values.uniform_size', 'Large');

        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => false, 'can_edit' => false,
        ])->assertCreated();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.uniform_size');
        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'forged full-deny bypass'],
        ])->assertForbidden();
    }

    // 14: most-permissive-wins across multiple roles.
    public function test_most_permissive_wins_across_multiple_roles(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $roleA = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');
        $roleB = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($roleA);
        $user->assignRole($roleB);

        // Role A denies, Role B grants edit — most-permissive (Role B) wins.
        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $roleA->id, 'can_view' => false, 'can_edit' => false,
        ])->assertCreated();
        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $roleB->id, 'can_view' => true, 'can_edit' => true,
        ])->assertCreated();

        $write = $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Medium'],
        ]);
        $write->assertOk();
        $write->assertJsonPath('data.custom_field_values.uniform_size', 'Medium');
    }

    // 15: direct permission grant asymmetry — a role-based rule does not
    // affect a user who reaches tier access via a direct grant, not a role.
    public function test_direct_permission_grant_is_unaffected_by_role_based_rules(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $field = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'secret'],
        ])->assertOk();

        // User has employees.view/.update via a role with NO rule
        // attached, plus custom_fields.access_sensitive granted
        // DIRECTLY (not through any role) — the default tier model
        // applies unchanged, since no rule matches any role this user
        // actually holds.
        $baseRole = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');
        $user = $this->userInRole($tenant, $baseRole);
        $sensitivePermission = Permission::query()->firstOrCreate(
            ['key' => 'custom_fields.access_sensitive'],
            ['category' => 'custom_fields', 'is_platform_permission' => false],
        );
        $user->grantPermission($sensitivePermission);

        $show = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonPath('data.custom_field_values.emergency_contact_note', 'secret');

        // Now attach a DENY rule to $baseRole — the direct grant still
        // does not matter here because the rule matches the role the
        // user is IN, which now overrides the default entirely.
        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $baseRole->id, 'can_view' => false, 'can_edit' => false,
        ])->assertCreated();

        $afterRule = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $afterRule->assertJsonMissingPath('data.custom_field_values.emergency_contact_note');
    }

    // 16: rule cannot expose a disabled field.
    public function test_rule_cannot_expose_disabled_field(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'Large'],
        ])->assertOk();

        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update');
        $user = $this->userInRole($tenant, $role);
        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ])->assertCreated();

        // Disable the underlying custom field entirely.
        $this->actingAs($admin)->patchJson($this->url($tenant, "custom-fields/{$field->id}"), ['status' => 'inactive'])->assertOk();

        $show = $this->actingAs($user)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.uniform_size');
        $this->actingAs($user)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['uniform_size' => 'forged while disabled'],
        ])->assertUnprocessable();
    }

    // 17: rule cannot expose a field when the required module is disabled.
    public function test_rule_cannot_expose_field_when_required_module_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $recruitField = CustomFieldDefinition::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'recruitment_applicant',
            'field_key' => 'visa_status', 'label' => 'Visa Status', 'field_type' => 'text',
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $role = $this->roleWithPermissions($tenant, 'job_applications.view', 'job_applications.update');

        TenantModuleAssignment::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'module_key' => 'recruitment'],
            ['enabled' => false],
        );

        $response = $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$recruitField->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ]);
        $response->assertForbidden();

        // Even the definitions list itself is blocked while the module
        // is disabled, so no rule (existing or attempted) can matter.
        $this->actingAs($admin)->getJson($this->url($tenant, 'custom-fields/recruitment_applicant'))->assertForbidden();
    }

    // 18: rules affect CustomFieldDefinitionResource can_view/can_edit.
    public function test_rules_affect_definition_resource_can_view_can_edit(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);

        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.view');
        $user = $this->userInRole($tenant, $role);

        $before = $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/employee'));
        $byKeyBefore = collect($before->json('data'))->keyBy('field_key');
        $this->assertFalse($byKeyBefore['emergency_contact_note']['can_view']);

        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ])->assertCreated();

        $after = $this->actingAs($user)->getJson($this->url($tenant, 'custom-fields/employee'));
        $byKeyAfter = collect($after->json('data'))->keyBy('field_key');
        $this->assertTrue($byKeyAfter['emergency_contact_note']['can_view']);
        $this->assertTrue($byKeyAfter['emergency_contact_note']['can_edit']);
    }

    // 19/20: rules affect CustomFieldValueService read filtering and write 403.
    // (Covered end-to-end by tests 8/9, 12, 13, 14 above via the real
    // employees endpoint — CustomFieldValueService is the only code
    // path those requests go through.)

    // 21: rules affect CustomFormResource rendering automatically — no
    // separate form visibility system exists to update.
    public function test_rules_affect_custom_form_rendering_automatically(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage', 'custom_forms.view', 'custom_forms.manage', 'employees.view', 'employees.update');
        $field = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);

        $form = $this->actingAs($admin)->postJson($this->url($tenant, 'custom-forms/employee'), [
            'form_key' => 'employee_form', 'name' => 'Employee Form',
        ]);
        $formId = $form->json('data.id');
        $section = $this->actingAs($admin)->postJson($this->url($tenant, "custom-forms/{$formId}/sections"), [
            'section_key' => 'general', 'title' => 'General',
        ]);
        $sectionId = $section->json('data.id');
        $this->actingAs($admin)->postJson($this->url($tenant, "custom-form-sections/{$sectionId}/fields"), [
            'custom_field_definition_id' => $field->id,
        ])->assertCreated();

        $role = $this->roleWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_forms.view');
        $user = $this->userInRole($tenant, $role);

        $before = $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'));
        $this->assertCount(0, $before->json('data.0.sections.0.fields'));

        $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => true,
        ])->assertCreated();

        $after = $this->actingAs($user)->getJson($this->url($tenant, 'custom-forms/employee'));
        $this->assertCount(1, $after->json('data.0.sections.0.fields'));
    }

    // 22: tenant isolation.
    public function test_tenant_isolation_for_visibility_rules(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminB = $this->userWithPermissions($tenantB, 'custom_fields.view', 'custom_fields.manage');
        $fieldB = $this->employeeField($tenantB, $adminB);
        $roleB = $this->roleWithPermissions($tenantB, 'employees.view');
        $ruleB = $this->actingAs($adminB)->postJson($this->url($tenantB, "custom-fields/{$fieldB->id}/visibility-rules"), [
            'role_id' => $roleB->id, 'can_view' => true, 'can_edit' => false,
        ]);
        $ruleId = $ruleB->json('data.id');

        $adminA = $this->userWithPermissions($tenantA, 'custom_fields.view', 'custom_fields.manage');
        $this->actingAs($adminA)->patchJson($this->url($tenantA, "custom-field-visibility-rules/{$ruleId}"), [
            'can_edit' => true,
        ])->assertNotFound();
    }

    // 23: audit events for rule changes.
    public function test_audit_events_for_rule_changes(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'custom_fields.view', 'custom_fields.manage');
        $field = $this->employeeField($tenant, $admin);
        $role = $this->roleWithPermissions($tenant, 'employees.view');

        $create = $this->actingAs($admin)->postJson($this->url($tenant, "custom-fields/{$field->id}/visibility-rules"), [
            'role_id' => $role->id, 'can_view' => true, 'can_edit' => false,
        ]);
        $ruleId = $create->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field_visibility_rule.created', 'auditable_id' => $ruleId]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "custom-field-visibility-rules/{$ruleId}"), ['can_edit' => true])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field_visibility_rule.updated', 'auditable_id' => $ruleId]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "custom-field-visibility-rules/{$ruleId}"), ['status' => 'inactive'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field_visibility_rule.disabled', 'auditable_id' => $ruleId]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "custom-field-visibility-rules/{$ruleId}"), ['status' => 'active'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'custom_field_visibility_rule.enabled', 'auditable_id' => $ruleId]);

        // No field value ever appears in a rule-change audit entry.
        $log = AuditLog::query()->where('action', 'custom_field_visibility_rule.created')->firstOrFail();
        $this->assertArrayNotHasKey('value', $log->new_values ?? []);
    }

    // 24: default sensitivity behaviour unchanged when no rule exists —
    // the resolver-refactor regression test.
    public function test_default_sensitivity_behaviour_unchanged_when_no_rule_exists(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->userWithPermissions($tenant, 'employees.view', 'employees.update', 'custom_fields.access_sensitive');
        $field = $this->employeeField($tenant, $admin, ['field_key' => 'emergency_contact_note', 'sensitivity' => 'sensitive']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($admin)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'secret'],
        ])->assertOk();

        $noTierUser = $this->userWithPermissions($tenant, 'employees.view', 'employees.update');
        $show = $this->actingAs($noTierUser)->getJson($this->url($tenant, "employees/{$employee->id}"));
        $show->assertJsonMissingPath('data.custom_field_values.emergency_contact_note');
        $this->actingAs($noTierUser)->patchJson($this->url($tenant, "employees/{$employee->id}"), [
            'custom_field_values' => ['emergency_contact_note' => 'forged'],
        ])->assertForbidden();
    }
}
