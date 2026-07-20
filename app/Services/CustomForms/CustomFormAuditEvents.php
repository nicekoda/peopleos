<?php

namespace App\Services\CustomForms;

use App\Models\CustomFieldDefinition;
use App\Models\CustomForm;
use App\Models\CustomFormField;
use App\Models\CustomFormSection;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Checkpoint 52 — centralizes every `custom_form.*` config-audit call,
 * mirroring CustomFieldAuditEvents' own shape exactly. This class only
 * ever audits form/section/field *configuration* changes — value
 * changes continue firing `custom_field.value_updated` exclusively via
 * the existing CustomFieldAuditEvents, completely independent of forms.
 * No form-submission value event is ever created here.
 */
class CustomFormAuditEvents
{
    public static function formCreated(CustomForm $form, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.created',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomForm::class,
            auditableId: $form->id,
            description: "Custom form '{$form->name}' created for {$form->entity_type->value}.",
            newValues: [
                'form_key' => $form->form_key,
                'entity_type' => $form->entity_type->value,
                'name' => $form->name,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $new
     */
    public static function formUpdated(CustomForm $form, array $previous, array $new, User $actor): void
    {
        if ($previous === $new) {
            return;
        }

        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.updated',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomForm::class,
            auditableId: $form->id,
            description: "Custom form '{$form->form_key}' updated.",
            oldValues: $previous,
            newValues: $new,
        );
    }

    public static function formStatusChanged(CustomForm $form, bool $nowEnabled, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: $nowEnabled ? 'custom_form.enabled' : 'custom_form.disabled',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomForm::class,
            auditableId: $form->id,
            description: "Custom form '{$form->form_key}' ".($nowEnabled ? 're-enabled.' : 'disabled.'),
            metadata: ['form_key' => $form->form_key],
        );
    }

    public static function sectionAdded(CustomForm $form, CustomFormSection $section, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.section_added',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomFormSection::class,
            auditableId: $section->id,
            description: "Section '{$section->title}' added to custom form '{$form->form_key}'.",
            metadata: ['form_key' => $form->form_key, 'section_key' => $section->section_key],
        );
    }

    public static function sectionUpdated(CustomForm $form, CustomFormSection $section, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.section_updated',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomFormSection::class,
            auditableId: $section->id,
            description: "Section '{$section->section_key}' updated on custom form '{$form->form_key}'.",
            metadata: ['form_key' => $form->form_key, 'section_key' => $section->section_key],
        );
    }

    public static function sectionRemoved(CustomForm $form, CustomFormSection $section, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.section_removed',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomFormSection::class,
            auditableId: $section->id,
            description: "Section '{$section->section_key}' disabled on custom form '{$form->form_key}' — historical values preserved.",
            metadata: ['form_key' => $form->form_key, 'section_key' => $section->section_key],
        );
    }

    public static function fieldAdded(CustomForm $form, CustomFormSection $section, CustomFormField $field, CustomFieldDefinition $definition, User $actor): void
    {
        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.field_added',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomFormField::class,
            auditableId: $field->id,
            description: "Field '{$definition->field_key}' added to section '{$section->section_key}' on custom form '{$form->form_key}'.",
            metadata: [
                'form_key' => $form->form_key,
                'section_key' => $section->section_key,
                'field_key' => $definition->field_key,
            ],
        );
    }

    public static function fieldUpdated(CustomForm $form, CustomFormSection $section, CustomFormField $field, User $actor): void
    {
        $fieldKey = $field->customFieldDefinition->field_key;

        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.field_updated',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomFormField::class,
            auditableId: $field->id,
            description: "Field '{$fieldKey}' updated in section '{$section->section_key}' on custom form '{$form->form_key}'.",
            metadata: [
                'form_key' => $form->form_key,
                'section_key' => $section->section_key,
                'field_key' => $fieldKey,
            ],
        );
    }

    public static function fieldRemoved(CustomForm $form, CustomFormSection $section, CustomFormField $field, User $actor): void
    {
        $fieldKey = $field->customFieldDefinition->field_key;

        AuditLogger::logFor(
            actor: $actor,
            action: 'custom_form.field_removed',
            module: 'custom_forms',
            tenantId: $form->tenant_id,
            auditableType: CustomFormField::class,
            auditableId: $field->id,
            description: "Field '{$fieldKey}' disabled in section '{$section->section_key}' on custom form '{$form->form_key}' — historical values preserved.",
            metadata: [
                'form_key' => $form->form_key,
                'section_key' => $section->section_key,
                'field_key' => $fieldKey,
            ],
        );
    }
}
