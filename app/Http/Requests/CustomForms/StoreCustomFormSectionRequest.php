<?php

namespace App\Http\Requests\CustomForms;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomFormSectionRequest extends FormRequest
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
            'section_key' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
