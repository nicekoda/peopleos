<?php

namespace App\Http\Requests\CustomForms;

use App\Enums\CustomFormStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * form_key and entity_type are deliberately absent from these rules
 * entirely (not merely ignored) — immutable after creation, same
 * posture as UpdateCustomFieldDefinitionRequest's own field_key
 * omission.
 */
class UpdateCustomFormRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(CustomFormStatus::class)],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
