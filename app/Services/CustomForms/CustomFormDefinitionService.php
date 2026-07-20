<?php

namespace App\Services\CustomForms;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFormStatus;
use App\Models\CustomFieldDefinition;
use App\Models\CustomForm;
use App\Models\CustomFormField;
use App\Models\CustomFormSection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checkpoint 52 — all form/section/field configuration guardrails live
 * here, mirroring CustomFieldDefinitionService's own shape exactly:
 * key format + uniqueness + immutability, transaction-wrapped writes
 * (Checkpoint 48 lesson — a failed validation must never leave an
 * orphaned active row behind), and config-change audit events.
 *
 * This service never touches `custom_field_values` — reading/writing
 * values remains exclusively CustomFieldValueService's job, called from
 * the owning entity's own controller (EmployeeController today),
 * completely unaware that a form even exists. A form is metadata that
 * groups/labels/orders existing fields; it is never a second value
 * pipeline.
 */
class CustomFormDefinitionService
{
    /**
     * Same pattern CustomFieldDefinitionService uses for field_key —
     * stable enough to be safely referenced later by workflows/reports/
     * AI without ever needing escaping.
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForm(Tenant $tenant, CustomFieldEntity $entityType, array $data, User $actor): CustomForm
    {
        $formKey = $data['form_key'];

        $this->assertKeyFormat($formKey, 'form_key');
        $this->assertFormKeyUnique($tenant, $entityType, $formKey);

        $form = DB::transaction(function () use ($tenant, $entityType, $data, $formKey, $actor) {
            return CustomForm::query()->create([
                'tenant_id' => $tenant->id,
                'entity_type' => $entityType->value,
                'form_key' => $formKey,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => CustomFormStatus::Active->value,
                'sort_order' => $data['sort_order'] ?? 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });

        CustomFormAuditEvents::formCreated($form, $actor);

        return $form->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateForm(CustomForm $form, array $data, User $actor): CustomForm
    {
        // form_key and entity_type are never accepted here — omitted
        // entirely from UpdateCustomFormRequest's rules, same posture
        // as CustomFieldDefinitionService::update() with field_key.
        $before = $form->only(['name', 'description', 'sort_order', 'status']);
        $previousStatus = $form->status;

        DB::transaction(function () use ($form, $data, $actor) {
            $form->fill(array_intersect_key($data, array_flip(['name', 'description', 'sort_order', 'status'])));
            $form->updated_by = $actor->id;
            $form->save();
        });

        $after = $form->only(['name', 'description', 'sort_order', 'status']);
        CustomFormAuditEvents::formUpdated($form, $before, $after, $actor);

        if ($form->status !== $previousStatus) {
            CustomFormAuditEvents::formStatusChanged($form, $form->status === CustomFormStatus::Active, $actor);
        }

        return $form->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSection(CustomForm $form, array $data, User $actor): CustomFormSection
    {
        $sectionKey = $data['section_key'];

        $this->assertKeyFormat($sectionKey, 'section_key');
        $this->assertSectionKeyUnique($form, $sectionKey);

        $section = DB::transaction(function () use ($form, $data, $sectionKey, $actor) {
            return CustomFormSection::query()->create([
                'custom_form_id' => $form->id,
                'section_key' => $sectionKey,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => CustomFormStatus::Active->value,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });

        CustomFormAuditEvents::sectionAdded($form, $section, $actor);

        return $section->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(CustomFormSection $section, array $data, User $actor): CustomFormSection
    {
        // section_key is never accepted here — immutable after creation.
        $previousStatus = $section->status;

        DB::transaction(function () use ($section, $data, $actor) {
            $section->fill(array_intersect_key($data, array_flip(['title', 'description', 'sort_order', 'status'])));
            $section->updated_by = $actor->id;
            $section->save();
        });

        CustomFormAuditEvents::sectionUpdated($section->form, $section, $actor);

        if ($previousStatus === CustomFormStatus::Active && $section->status === CustomFormStatus::Inactive) {
            CustomFormAuditEvents::sectionRemoved($section->form, $section, $actor);
        }

        return $section->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createField(CustomFormSection $section, array $data, User $actor): CustomFormField
    {
        $definition = $this->resolveFieldDefinitionForSection($section, $data['custom_field_definition_id']);
        $this->assertFieldNotAlreadyInSection($section, $definition);

        $field = DB::transaction(function () use ($section, $definition, $data, $actor) {
            return CustomFormField::query()->create([
                'custom_form_section_id' => $section->id,
                'custom_field_definition_id' => $definition->id,
                'label_override' => $data['label_override'] ?? null,
                'help_text' => $data['help_text'] ?? null,
                'placeholder' => $data['placeholder'] ?? null,
                'is_required_override' => $data['is_required_override'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => CustomFormStatus::Active->value,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });

        CustomFormAuditEvents::fieldAdded($section->form, $section, $field, $definition, $actor);

        return $field->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateField(CustomFormField $field, array $data, User $actor): CustomFormField
    {
        // custom_form_section_id and custom_field_definition_id are
        // never accepted here — a field's section and underlying
        // custom field are immutable once created; remove and re-add
        // to point a form field at a different custom field.
        $previousStatus = $field->status;

        DB::transaction(function () use ($field, $data, $actor) {
            $field->fill(array_intersect_key($data, array_flip([
                'label_override', 'help_text', 'placeholder', 'is_required_override', 'sort_order', 'status',
            ])));
            $field->updated_by = $actor->id;
            $field->save();
        });

        $section = $field->section;
        CustomFormAuditEvents::fieldUpdated($section->form, $section, $field, $actor);

        if ($previousStatus === CustomFormStatus::Active && $field->status === CustomFormStatus::Inactive) {
            CustomFormAuditEvents::fieldRemoved($section->form, $section, $field, $actor);
        }

        return $field->fresh();
    }

    private function assertKeyFormat(string $key, string $attribute): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw ValidationException::withMessages([
                $attribute => ["'{$attribute}' must be lowercase snake_case, start with a letter, contain only letters/numbers/underscores, and be 60 characters or fewer."],
            ]);
        }
    }

    private function assertFormKeyUnique(Tenant $tenant, CustomFieldEntity $entityType, string $formKey): void
    {
        $exists = CustomForm::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', $entityType->value)
            ->where('form_key', $formKey)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'form_key' => ["'{$formKey}' already exists for this entity."],
            ]);
        }
    }

    private function assertSectionKeyUnique(CustomForm $form, string $sectionKey): void
    {
        $exists = $form->sections()->where('section_key', $sectionKey)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'section_key' => ["'{$sectionKey}' already exists for this form."],
            ]);
        }
    }

    /**
     * Defense in depth, not just a convenience lookup: re-verifies the
     * referenced custom field definition belongs to the same tenant AND
     * the same entity_type as the form it's being added to — a request
     * could otherwise attach a recruitment_applicant field to an
     * employee form, or another tenant's field entirely, if this
     * weren't independently re-checked here (never trust that whatever
     * gated the caller into the controller already proved this).
     */
    private function resolveFieldDefinitionForSection(CustomFormSection $section, string $customFieldDefinitionId): CustomFieldDefinition
    {
        $form = $section->form;

        /** @var CustomFieldDefinition|null $definition */
        $definition = CustomFieldDefinition::query()->find($customFieldDefinitionId);

        if ($definition === null
            || $definition->tenant_id !== $form->tenant_id
            || $definition->entity_type !== $form->entity_type
        ) {
            throw ValidationException::withMessages([
                'custom_field_definition_id' => ['This custom field does not belong to the same tenant/entity as this form.'],
            ]);
        }

        return $definition;
    }

    private function assertFieldNotAlreadyInSection(CustomFormSection $section, CustomFieldDefinition $definition): void
    {
        $exists = $section->fields()->where('custom_field_definition_id', $definition->id)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'custom_field_definition_id' => ["'{$definition->field_key}' is already in this section."],
            ]);
        }
    }
}
