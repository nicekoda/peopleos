<?php

namespace App\Http\Requests\CustomFields;

use App\Enums\CustomFieldVisibilityRuleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * role_id and custom_field_definition_id are deliberately absent —
 * immutable after creation, same posture as every other immutable-key
 * relationship in this subsystem (form_key, section_key,
 * custom_field_definition_id on a CustomFormField).
 */
class UpdateCustomFieldVisibilityRuleRequest extends FormRequest
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
            'can_view' => ['sometimes', 'boolean'],
            'can_edit' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::enum(CustomFieldVisibilityRuleStatus::class)],
        ];
    }
}
