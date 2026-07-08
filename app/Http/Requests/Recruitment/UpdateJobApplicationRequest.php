<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Contact/detail fields only — stage changes go through
 * PATCH .../stage (UpdateApplicationStageRequest), and
 * ready_for_conversion through PATCH .../ready-for-conversion
 * (MarkApplicationReadyForConversionRequest). Keeping those as separate,
 * narrowly-permissioned actions rather than folding them into this
 * general update — same reasoning as HrGeneratedDocument's submit/
 * approve/reject being split from its own PATCH.
 */
class UpdateJobApplicationRequest extends FormRequest
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
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:255'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
