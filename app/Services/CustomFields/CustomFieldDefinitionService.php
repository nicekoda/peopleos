<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldDefinitionStatus;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldSensitivity;
use App\Enums\CustomFieldType;
use App\Enums\CustomFieldValidationRuleKey;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValidationRule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checkpoint 48 — all definition-level guardrails live here, not spread
 * across the controller/FormRequest, so every entry point (now just one
 * controller, later possibly more) gets the same enforcement:
 * field_key format + reserved-key rejection, the per-(tenant, entity)
 * field cap, field_key/option_key immutability, the field_type-change
 * lock once values exist, and default_value validation against the
 * field's own type/options/rules.
 */
class CustomFieldDefinitionService
{
    /**
     * MVP guardrail (Checkpoint 48, decision 6) — prevents unbounded
     * field sprawl from degrading the entity's own list/show endpoints
     * and any future forms/reports built on top. A future entitlement
     * layer may make this package/plan-dependent (see
     * docs/platform-vision.md) — a flat constant for now.
     */
    public const MAX_FIELDS_PER_TENANT_ENTITY = 50;

    /**
     * Lowercase snake_case, must start with a letter, letters/digits/
     * underscores only, max 60 chars — stable enough to be safely
     * referenced later by forms/workflow conditions/reports/AI filters/
     * API integrations without ever needing escaping.
     */
    private const FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $options
     * @param  list<array<string, mixed>>  $validationRules
     */
    public function create(
        Tenant $tenant,
        CustomFieldEntity $entityType,
        array $data,
        array $options,
        array $validationRules,
        User $actor,
    ): CustomFieldDefinition {
        $fieldKey = $data['field_key'];
        $fieldType = CustomFieldType::from($data['field_type']);

        $this->assertFieldKeyFormat($fieldKey);
        $this->assertNotReserved($entityType, $fieldKey);
        $this->assertUnderMaxFields($tenant, $entityType);
        $this->assertFieldKeyUnique($tenant, $entityType, $fieldKey);

        // The whole definition (row, options, validation rules, default
        // value) must succeed or fail together — a validation failure
        // partway through (e.g. an invalid option key, or a default
        // value that fails validation) must never leave a half-created,
        // unusable definition row behind. Found live: creating a field
        // with a bad default value returned 422, but the definition row
        // itself had already been inserted before that check ran.
        $definition = DB::transaction(function () use ($tenant, $entityType, $data, $fieldKey, $fieldType, $options, $validationRules, $actor) {
            $definition = CustomFieldDefinition::query()->create([
                'tenant_id' => $tenant->id,
                'entity_type' => $entityType->value,
                'field_key' => $fieldKey,
                'label' => $data['label'],
                'description' => $data['description'] ?? null,
                'field_type' => $fieldType->value,
                'is_required' => $data['is_required'] ?? false,
                'sensitivity' => $data['sensitivity'] ?? CustomFieldSensitivity::Normal->value,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => CustomFieldDefinitionStatus::Active->value,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncValidationRules($definition, $fieldType, $validationRules);
            $this->syncOptions($definition, $options, $actor);

            if (array_key_exists('default_value', $data) && $data['default_value'] !== null && $data['default_value'] !== '') {
                $definition->refresh();
                $definition->load(['options', 'validationRules']);
                $this->applyDefaultValue($definition, $data['default_value']);
            }

            return $definition;
        });

        CustomFieldAuditEvents::created($definition, $actor);

        return $definition->fresh(['options', 'validationRules']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $options
     * @param  list<array<string, mixed>>|null  $validationRules
     */
    public function update(
        CustomFieldDefinition $definition,
        array $data,
        ?array $options,
        ?array $validationRules,
        User $actor,
    ): CustomFieldDefinition {
        // field_key and entity_type are never accepted here at all — see
        // the FormRequest, which omits them from its update rules
        // entirely (not merely ignored) so a resent value can't even be
        // silently dropped in a way that looks like it was considered.
        $before = $definition->only(['label', 'description', 'field_type', 'is_required', 'sensitivity', 'sort_order', 'status']);
        $previousStatus = $definition->status;

        // Same "all or nothing" reasoning as create(): a validation
        // failure partway through (e.g. an invalid option, or a default
        // value that no longer fits after a field_type change) must
        // never leave the definition half-updated — label/status
        // changes committed while options/default-value changes silently
        // failed.
        DB::transaction(function () use ($definition, $data, $options, $validationRules, $actor) {
            if (array_key_exists('field_type', $data)) {
                $newType = CustomFieldType::from($data['field_type']);

                if ($newType !== $definition->field_type && $definition->values()->exists()) {
                    throw ValidationException::withMessages([
                        'field_type' => ['This field already has stored values — its type cannot be changed. Create a new field instead.'],
                    ]);
                }

                $definition->field_type = $newType->value;
            }

            $definition->fill(array_intersect_key($data, array_flip([
                'label', 'description', 'is_required', 'sensitivity', 'sort_order', 'status',
            ])));
            $definition->updated_by = $actor->id;
            $definition->save();

            if ($validationRules !== null) {
                $this->syncValidationRules($definition, $definition->field_type, $validationRules);
            }

            if ($options !== null) {
                $this->syncOptions($definition, $options, $actor);
            }

            if (array_key_exists('default_value', $data)) {
                $definition->refresh();
                $definition->load(['options', 'validationRules']);

                if ($data['default_value'] === null || $data['default_value'] === '') {
                    $definition->default_value = null;
                    $definition->save();
                } else {
                    $this->applyDefaultValue($definition, $data['default_value']);
                }
            }
        });

        $after = $definition->only(['label', 'description', 'field_type', 'is_required', 'sensitivity', 'sort_order', 'status']);
        CustomFieldAuditEvents::updated($definition, $before, $after, $actor);

        if ($definition->status !== $previousStatus) {
            CustomFieldAuditEvents::statusChanged($definition, $definition->status === CustomFieldDefinitionStatus::Active, $actor);
        }

        return $definition->fresh(['options', 'validationRules']);
    }

    private function assertFieldKeyFormat(string $fieldKey): void
    {
        if (preg_match(self::FIELD_KEY_PATTERN, $fieldKey) !== 1) {
            throw ValidationException::withMessages([
                'field_key' => ['Field key must be lowercase snake_case, start with a letter, contain only letters/numbers/underscores, and be 60 characters or fewer.'],
            ]);
        }
    }

    private function assertNotReserved(CustomFieldEntity $entityType, string $fieldKey): void
    {
        if (in_array($fieldKey, $entityType->reservedFieldKeys(), true)) {
            throw ValidationException::withMessages([
                'field_key' => ["'{$fieldKey}' is a reserved field key and cannot be used."],
            ]);
        }
    }

    private function assertUnderMaxFields(Tenant $tenant, CustomFieldEntity $entityType): void
    {
        $count = CustomFieldDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', $entityType->value)
            ->count();

        if ($count >= self::MAX_FIELDS_PER_TENANT_ENTITY) {
            throw ValidationException::withMessages([
                'field_key' => ['This entity has reached the maximum of '.self::MAX_FIELDS_PER_TENANT_ENTITY.' custom fields.'],
            ]);
        }
    }

    /**
     * A clean 422 instead of a raw unique-constraint violation — the
     * database's `unique(tenant_id, entity_type, field_key)` index is
     * still the real guarantee (defense in depth), this is just a
     * friendlier first check.
     */
    private function assertFieldKeyUnique(Tenant $tenant, CustomFieldEntity $entityType, string $fieldKey): void
    {
        $exists = CustomFieldDefinition::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', $entityType->value)
            ->where('field_key', $fieldKey)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'field_key' => ["'{$fieldKey}' already exists for this entity."],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $validationRules
     */
    private function syncValidationRules(CustomFieldDefinition $definition, CustomFieldType $fieldType, array $validationRules): void
    {
        $allowed = $fieldType->allowedValidationRuleKeys();

        foreach ($validationRules as $rule) {
            $ruleKey = CustomFieldValidationRuleKey::from($rule['rule_key']);

            if (! in_array($ruleKey, $allowed, true)) {
                throw ValidationException::withMessages([
                    'validation_rules' => ["'{$ruleKey->value}' does not apply to field type '{$fieldType->value}'."],
                ]);
            }

            if ($ruleKey->requiresRuleValue() && (! isset($rule['rule_value']) || $rule['rule_value'] === '')) {
                throw ValidationException::withMessages([
                    'validation_rules' => ["'{$ruleKey->value}' requires a rule value."],
                ]);
            }
        }

        $definition->validationRules()->delete();

        foreach ($validationRules as $rule) {
            CustomFieldValidationRule::query()->create([
                'custom_field_definition_id' => $definition->id,
                'rule_key' => $rule['rule_key'],
                'rule_value' => $rule['rule_value'] ?? null,
            ]);
        }
    }

    /**
     * Upsert-by-option_key — option_key is immutable once created
     * (Checkpoint 48, decision 9); an option is never hard-deleted, only
     * flipped to `inactive` (audited as `custom_field.option_removed`),
     * preserving historical values that reference it.
     *
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(CustomFieldDefinition $definition, array $options, User $actor): void
    {
        if ($options === [] && ! $definition->field_type->usesOptions()) {
            return;
        }

        $existing = $definition->options()->get()->keyBy('option_key');

        foreach ($options as $option) {
            $optionKey = $option['option_key'];

            if (preg_match(self::FIELD_KEY_PATTERN, $optionKey) !== 1) {
                throw ValidationException::withMessages([
                    'options' => ["Option key '{$optionKey}' must be lowercase snake_case, start with a letter, and contain only letters/numbers/underscores."],
                ]);
            }

            /** @var CustomFieldOption|null $current */
            $current = $existing->get($optionKey);

            if ($current !== null) {
                $current->label = $option['label'] ?? $current->label;
                $current->sort_order = $option['sort_order'] ?? $current->sort_order;

                $wasActive = $current->status === CustomFieldDefinitionStatus::Active;
                $current->status = $option['status'] ?? $current->status->value;
                $current->updated_by = $actor->id;
                $current->save();

                $nowActive = $current->status === CustomFieldDefinitionStatus::Active;

                if ($wasActive && ! $nowActive) {
                    CustomFieldAuditEvents::optionRemoved($definition, $current, $actor);
                }
            } else {
                $created = CustomFieldOption::query()->create([
                    'custom_field_definition_id' => $definition->id,
                    'option_key' => $optionKey,
                    'label' => $option['label'],
                    'sort_order' => $option['sort_order'] ?? 0,
                    'status' => $option['status'] ?? CustomFieldDefinitionStatus::Active->value,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                CustomFieldAuditEvents::optionAdded($definition, $created, $actor);
            }
        }
    }

    private function applyDefaultValue(CustomFieldDefinition $definition, mixed $rawDefault): void
    {
        $decoded = $definition->field_type === CustomFieldType::MultiSelect && is_string($rawDefault)
            ? json_decode($rawDefault, true)
            : $rawDefault;

        // enforceActiveOptions=true — a default must itself be a
        // currently-selectable option, same as any other new write.
        $result = CustomFieldValueValidator::validate($definition, $decoded, enforceActiveOptions: true);

        $definition->default_value = is_array($result['value']) ? json_encode($result['value']) : (
            $result['value'] === null ? null : (string) $result['value']
        );
        $definition->save();
    }
}
