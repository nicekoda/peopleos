<?php

namespace App\Http\Requests\HrDocument;

use Illuminate\Foundation\Http\FormRequest;

class StoreHrDocumentTemplateVersionRequest extends FormRequest
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
            'content_template' => ['required', 'string', 'max:20000'],
        ];
    }
}
