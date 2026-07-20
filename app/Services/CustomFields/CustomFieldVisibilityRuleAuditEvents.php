<?php

namespace App\Services\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldVisibilityRule;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Checkpoint 53 — centralizes every `custom_field_visibility_rule.*`
 * config-audit call, mirroring CustomFormAuditEvents'/CustomFieldAuditEvents'
 * own shape. Only ever audits rule *configuration* changes (which role,
 * what can_view/can_edit) — never a field value. Value changes continue
 * firing `custom_field.value_updated` exclusively, via the unmodified
 * CustomFieldAuditEvents, completely independent of this class.
 */
class CustomFieldVisibilityRuleAuditEvents
{
    public static function created(CustomFieldDefinition $definition, CustomFieldVisibilityRule $rule, Role $role, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field_visibility_rule.created',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldVisibilityRule::class,
            auditableId: $rule->id,
            description: "Visibility rule created for field '{$definition->field_key}', role '{$role->name}'.",
            newValues: ['can_view' => $rule->can_view, 'can_edit' => $rule->can_edit],
            metadata: [
                'field_key' => $definition->field_key,
                'entity_type' => $definition->entity_type->value,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ],
        );
    }

    public static function updated(CustomFieldDefinition $definition, CustomFieldVisibilityRule $rule, Role $role, array $previous, array $new, User $actor): void
    {
        if ($previous === $new) {
            return;
        }

        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field_visibility_rule.updated',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldVisibilityRule::class,
            auditableId: $rule->id,
            description: "Visibility rule updated for field '{$definition->field_key}', role '{$role->name}'.",
            oldValues: $previous,
            newValues: $new,
            metadata: [
                'field_key' => $definition->field_key,
                'entity_type' => $definition->entity_type->value,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ],
        );
    }

    public static function statusChanged(CustomFieldDefinition $definition, CustomFieldVisibilityRule $rule, Role $role, bool $nowEnabled, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: $nowEnabled ? 'custom_field_visibility_rule.enabled' : 'custom_field_visibility_rule.disabled',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldVisibilityRule::class,
            auditableId: $rule->id,
            description: "Visibility rule for field '{$definition->field_key}', role '{$role->name}' ".($nowEnabled ? 're-enabled.' : 'disabled.'),
            metadata: [
                'field_key' => $definition->field_key,
                'entity_type' => $definition->entity_type->value,
                'role_id' => $role->id,
                'role_name' => $role->name,
            ],
        );
    }
}
