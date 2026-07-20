<?php

namespace App\Http\Requests\CustomForms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * custom_field_definition_id is re-verified (tenant + entity_type match)
 * in CustomFormDefinitionService, never trusted from this shape check
 * alone — see resolveFieldDefinitionForSection().
 */
class StoreCustomFormFieldRequest extends FormRequest
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
            'custom_field_definition_id' => ['required', 'string'],
            'label_override' => ['nullable', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:255'],
            // UI-only for Checkpoint 52 — never consulted by
            // CustomFieldValueValidator. See docs/architecture.md.
            'is_required_override' => ['nullable', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
