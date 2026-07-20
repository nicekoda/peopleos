<?php

namespace App\Http\Requests\CustomForms;

use App\Enums\CustomFormStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * custom_form_section_id and custom_field_definition_id are
 * deliberately absent — immutable after creation; remove and re-add to
 * point a form field at a different section/custom field.
 */
class UpdateCustomFormFieldRequest extends FormRequest
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
            'label_override' => ['nullable', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            'is_required_override' => ['nullable', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(CustomFormStatus::class)],
        ];
    }
}
