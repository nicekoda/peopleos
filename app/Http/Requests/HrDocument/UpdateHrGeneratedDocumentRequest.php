<?php

namespace App\Http\Requests\HrDocument;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHrGeneratedDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Title only — status is never editable through this endpoint.
     * Archiving is a dedicated action (DELETE, soft-delete-as-archive,
     * same shape as DocumentCategory/HrDocumentTemplate) so a status
     * transition can never be smuggled in through a generic update body.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
