<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * note only — visibility is always 'internal', set by the controller,
 * never accepted from request input (no candidate-facing notes exist
 * yet). created_by is controller-only.
 */
class StoreApplicationNoteRequest extends FormRequest
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
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
