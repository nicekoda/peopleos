<?php

namespace App\Http\Requests\CustomFields;

use App\Enums\CustomFieldDefinitionStatus;
use App\Enums\CustomFieldSensitivity;
use App\Enums\CustomFieldType;
use App\Enums\CustomFieldValidationRuleKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * entity_type is a route parameter (resolved/validated in the
 * controller via CustomFieldEntity::tryFrom(), 422 on an unknown value —
 * same non-defensive-enum-route-param technique as
 * TenantModuleController), never part of this request body.
 *
 * field_key format/reserved-key/max-fields checks, and default_value
 * validation against the field's own type/options/rules, all happen in
 * CustomFieldDefinitionService — this class only enforces request shape.
 */
class StoreCustomFieldDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'field_key' => ['required', 'string', 'max:60'],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'field_type' => ['required', Rule::enum(CustomFieldType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'default_value' => ['nullable'],
            'sensitivity' => ['sometimes', Rule::enum(CustomFieldSensitivity::class)],
            'sort_order' => ['sometimes', 'integer'],

            'options' => ['sometimes', 'array'],
            'options.*.option_key' => ['required_with:options', 'string', 'max:60'],
            'options.*.label' => ['required_with:options', 'string', 'max:255'],
            'options.*.sort_order' => ['sometimes', 'integer'],
            'options.*.status' => ['sometimes', Rule::enum(CustomFieldDefinitionStatus::class)],

            'validation_rules' => ['sometimes', 'array'],
            'validation_rules.*.rule_key' => ['required_with:validation_rules', Rule::enum(CustomFieldValidationRuleKey::class)],
            'validation_rules.*.rule_value' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fieldType = $this->input('field_type');
            $usesOptions = in_array($fieldType, ['single_select', 'multi_select'], true);

            if ($usesOptions && $this->input('options', []) === []) {
                $validator->errors()->add('options', 'This field type requires at least one option.');
            }
        });
    }
}
