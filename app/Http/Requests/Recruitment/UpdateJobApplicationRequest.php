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
            // Checkpoint 48 — the applicant's (RecruitmentApplicant)
            // custom field values, keyed by field_key. Per-key type/
            // rule/option validation is dynamic per tenant and happens
            // in CustomFieldValueService/CustomFieldValueValidator, not
            // here — this only enforces the outer shape.
            'custom_field_values' => ['sometimes', 'array'],
            // Checkpoint 49 — this application's own (RecruitmentApplication)
            // custom field values. Deliberately a separate payload key
            // from custom_field_values above, never merged into one
            // object — the same field_key can validly exist on both
            // entities (e.g. a "notes" field on each), and a single
            // shared object would have no way to say which entity a key
            // belongs to.
            'application_custom_field_values' => ['sometimes', 'array'],
        ];
    }
}
