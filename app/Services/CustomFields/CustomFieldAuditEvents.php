<?php

namespace App\Services\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Checkpoint 48 — centralizes every `custom_field.*` audit call so the
 * classification-aware masking rule (decision 14) is applied in exactly
 * one place: `AuditValueSanitizer`/`AuditLogger`'s own masking is
 * name-pattern-based and can't know that a tenant-defined field like
 * "visa_status" is sensitive — only the field's own
 * CustomFieldSensitivity classification knows that, so `valueUpdated()`
 * below masks explicitly before ever calling AuditLogger, rather than
 * relying on name-pattern matching to catch it by accident.
 */
class CustomFieldAuditEvents
{
    private const MASK = '***MASKED***';

    public static function created(CustomFieldDefinition $definition, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field.created',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldDefinition::class,
            auditableId: $definition->id,
            description: "Custom field '{$definition->field_key}' created for {$definition->entity_type->value}.",
            newValues: [
                'field_key' => $definition->field_key,
                'field_type' => $definition->field_type->value,
                'entity_type' => $definition->entity_type->value,
                'sensitivity' => $definition->sensitivity->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $new
     */
    public static function updated(CustomFieldDefinition $definition, array $previous, array $new, User $actor): void
    {
        if ($previous === $new) {
            return;
        }

        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field.updated',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldDefinition::class,
            auditableId: $definition->id,
            description: "Custom field '{$definition->field_key}' updated.",
            oldValues: $previous,
            newValues: $new,
        );
    }

    public static function statusChanged(CustomFieldDefinition $definition, bool $nowEnabled, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: $nowEnabled ? 'custom_field.enabled' : 'custom_field.disabled',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldDefinition::class,
            auditableId: $definition->id,
            description: "Custom field '{$definition->field_key}' ".($nowEnabled ? 're-enabled.' : 'disabled — existing values preserved.'),
            metadata: ['field_key' => $definition->field_key],
        );
    }

    public static function optionAdded(CustomFieldDefinition $definition, CustomFieldOption $option, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field.option_added',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldDefinition::class,
            auditableId: $definition->id,
            description: "Option '{$option->option_key}' added to custom field '{$definition->field_key}'.",
            metadata: ['field_key' => $definition->field_key, 'option_key' => $option->option_key],
        );
    }

    public static function optionRemoved(CustomFieldDefinition $definition, CustomFieldOption $option, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field.option_removed',
            module: 'custom_fields',
            tenantId: $definition->tenant_id,
            auditableType: CustomFieldDefinition::class,
            auditableId: $definition->id,
            description: "Option '{$option->option_key}' removed from custom field '{$definition->field_key}' — historical values preserved.",
            metadata: ['field_key' => $definition->field_key, 'option_key' => $option->option_key],
        );
    }

    public static function valueUpdated(
        CustomFieldDefinition $definition,
        string $entityType,
        string $entityId,
        mixed $previousValue,
        mixed $newValue,
        User $actor,
        string $tenantId,
    ): void {
        $masked = $definition->sensitivity->requiresAuditMasking();

        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_field.value_updated',
            module: 'custom_fields',
            tenantId: $tenantId,
            auditableType: CustomFieldValue::class,
            auditableId: $definition->id,
            description: "Custom field '{$definition->field_key}' value updated.",
            oldValues: ['value' => $masked ? self::MASK : $previousValue],
            newValues: ['value' => $masked ? self::MASK : $newValue],
            metadata: [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'field_key' => $definition->field_key,
                'sensitivity' => $definition->sensitivity->value,
            ],
        );
    }
}
