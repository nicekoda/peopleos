<?php

namespace App\Http\Requests\CustomFields;

use App\Enums\CustomFieldDefinitionStatus;
use App\Enums\CustomFieldSensitivity;
use App\Enums\CustomFieldType;
use App\Enums\CustomFieldValidationRuleKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * field_key and entity_type are deliberately absent from these rules
 * entirely — not merely ignored if resent. field_key is immutable
 * (Checkpoint 48, decision 5); labels may change, keys never do.
 */
class UpdateCustomFieldDefinitionRequest extends FormRequest
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
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Allowed to change only while the field has no stored values
            // yet — enforced in CustomFieldDefinitionService, not here.
            'field_type' => ['sometimes', Rule::enum(CustomFieldType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'default_value' => ['sometimes', 'nullable'],
            'sensitivity' => ['sometimes', Rule::enum(CustomFieldSensitivity::class)],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(CustomFieldDefinitionStatus::class)],

            'options' => ['sometimes', 'array'],
            'options.*.option_key' => ['required_with:options', 'string', 'max:60'],
            'options.*.label' => ['sometimes', 'string', 'max:255'],
            'options.*.sort_order' => ['sometimes', 'integer'],
            'options.*.status' => ['sometimes', Rule::enum(CustomFieldDefinitionStatus::class)],

            'validation_rules' => ['sometimes', 'array'],
            'validation_rules.*.rule_key' => ['required_with:validation_rules', Rule::enum(CustomFieldValidationRuleKey::class)],
            'validation_rules.*.rule_value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
