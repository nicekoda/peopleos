<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldVisibilityRuleStatus;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldVisibilityRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checkpoint 53 — all visibility-rule guardrails live here, mirroring
 * CustomFormDefinitionService's own shape: role/tenant re-verification,
 * the Tenant Admin lockout safeguard, the can_edit-requires-can_view
 * invariant, transaction-wrapped writes, and config-change audit
 * events. Never touches `custom_field_values` — reading/writing values
 * remains exclusively CustomFieldValueService's job (itself now
 * delegating to CustomFieldAccessResolver, which is what actually
 * consults the rules this service manages).
 */
class CustomFieldVisibilityRuleService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createRule(CustomFieldDefinition $definition, array $data, User $actor): CustomFieldVisibilityRule
    {
        $role = $this->resolveRoleForDefinition($definition, $data['role_id']);
        $this->assertNotAlreadyRuled($definition, $role);
        $this->assertCanEditRequiresCanView($data['can_view'], $data['can_edit']);

        $rule = DB::transaction(function () use ($definition, $role, $data, $actor) {
            return CustomFieldVisibilityRule::query()->create([
                'custom_field_definition_id' => $definition->id,
                'role_id' => $role->id,
                'can_view' => $data['can_view'],
                'can_edit' => $data['can_edit'],
                'status' => 'active',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });

        CustomFieldVisibilityRuleAuditEvents::created($definition, $rule, $role, $actor);

        return $rule->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRule(CustomFieldVisibilityRule $rule, array $data, User $actor): CustomFieldVisibilityRule
    {
        // role_id and custom_field_definition_id are never accepted
        // here — immutable once created; disable and re-add to target a
        // different role.
        $definition = $rule->customFieldDefinition;
        $role = $rule->role;

        $canView = array_key_exists('can_view', $data) ? $data['can_view'] : $rule->can_view;
        $canEdit = array_key_exists('can_edit', $data) ? $data['can_edit'] : $rule->can_edit;
        $this->assertCanEditRequiresCanView($canView, $canEdit);

        $before = $rule->only(['can_view', 'can_edit', 'status']);
        $previousStatus = $rule->status;

        DB::transaction(function () use ($rule, $data, $canView, $canEdit, $actor) {
            $rule->can_view = $canView;
            $rule->can_edit = $canEdit;

            if (array_key_exists('status', $data)) {
                $rule->status = $data['status'];
            }

            $rule->updated_by = $actor->id;
            $rule->save();
        });

        $after = $rule->only(['can_view', 'can_edit', 'status']);
        CustomFieldVisibilityRuleAuditEvents::updated($definition, $rule, $role, $before, $after, $actor);

        if ($rule->status !== $previousStatus) {
            CustomFieldVisibilityRuleAuditEvents::statusChanged($definition, $rule, $role, $rule->status === CustomFieldVisibilityRuleStatus::Active, $actor);
        }

        return $rule->fresh();
    }

    /**
     * Defense in depth, not just a convenience lookup: re-verifies the
     * referenced role belongs to the same tenant as the field
     * definition, is a tenant role (never a platform role), and is not
     * the tenant's own Tenant Admin role — never trusts that a
     * frontend picker already filtered these out. Tenant Admin is the
     * one role guaranteed to always have full access (via its blanket
     * permission grant) and the only role capable of managing these
     * rules in the first place — a rule targeting it would be the
     * first-ever way to lock a Tenant Admin out of their own tenant's
     * configuration.
     */
    private function resolveRoleForDefinition(CustomFieldDefinition $definition, string $roleId): Role
    {
        /** @var Role|null $role */
        $role = Role::query()->find($roleId);

        if ($role === null
            || $role->is_platform_role
            || $role->tenant_id !== $definition->tenant_id
        ) {
            throw ValidationException::withMessages([
                'role_id' => ['This role does not belong to the same tenant as this custom field.'],
            ]);
        }

        if ($role->slug === 'tenant-admin') {
            throw ValidationException::withMessages([
                'role_id' => ['A visibility rule cannot target the Tenant Admin role.'],
            ]);
        }

        return $role;
    }

    private function assertNotAlreadyRuled(CustomFieldDefinition $definition, Role $role): void
    {
        $exists = $definition->visibilityRules()->where('role_id', $role->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'role_id' => ["A visibility rule for role '{$role->name}' already exists on this field."],
            ]);
        }
    }

    private function assertCanEditRequiresCanView(bool $canView, bool $canEdit): void
    {
        if ($canEdit && ! $canView) {
            throw ValidationException::withMessages([
                'can_view' => ['can_edit cannot be true while can_view is false.'],
            ]);
        }
    }
}
