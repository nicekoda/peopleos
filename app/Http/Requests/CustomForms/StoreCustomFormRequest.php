<?php

namespace App\Http\Requests\CustomForms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * entity_type is a route parameter (resolved/validated in the
 * controller via CustomFieldEntity::tryFrom(), 422 on unknown — same
 * posture as CustomFieldDefinitionController), never part of this
 * request body. form_key format/uniqueness live in
 * CustomFormDefinitionService — this class only enforces request shape.
 */
class StoreCustomFormRequest extends FormRequest
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
            'form_key' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
