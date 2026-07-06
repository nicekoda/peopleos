<?php

namespace App\Http\Requests\HrDocument;

use Illuminate\Foundation\Http\FormRequest;

/**
 * rejection_reason only — status/rejected_at/rejected_by are never
 * accepted here; the controller sets them server-side after checking
 * the transition is valid (pending_approval -> rejected).
 */
class RejectHrGeneratedDocumentRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
