<?php

namespace App\Http\Requests\HrDocument;

use App\Enums\HrGeneratedDocumentStatus;
use App\Models\HrGeneratedDocument;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Title only — status is never editable through this endpoint.
 * Archiving is a dedicated action (DELETE, soft-delete-as-archive,
 * same shape as DocumentCategory/HrDocumentTemplate) so a status
 * transition can never be smuggled in through a generic update body.
 *
 * Checkpoint 37 — rejected entirely (422) unless the route-bound
 * document is currently `draft` or `rejected` (your approved rule: not
 * editable while pending_approval or once approved). Same
 * "withValidator() against the route-bound record's current status"
 * pattern UpdateLifecycleProcessRequest/UpdateHrDocumentTemplateVersionRequest
 * already established.
 */
class UpdateHrGeneratedDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var HrGeneratedDocument $document */
            $document = $this->route('hrGeneratedDocument');

            $editableStatuses = [HrGeneratedDocumentStatus::Draft, HrGeneratedDocumentStatus::Rejected];

            if (! in_array($document->status, $editableStatuses, true)) {
                $validator->errors()->add('title', 'This document can no longer be edited in its current status.');
            }
        });
    }
}
