<?php

namespace App\Http\Requests\CustomForms;

use App\Enums\CustomFormStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * section_key is deliberately absent — immutable after creation.
 */
class UpdateCustomFormSectionRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(CustomFormStatus::class)],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
