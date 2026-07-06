<?php

namespace App\Http\Requests\HrDocument;

use App\Enums\HrDocumentTemplateVersionStatus;
use App\Models\HrDocumentTemplateVersion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * content_template only — status/published_at/published_by/tenant_id/
 * created_by/updated_by are never accepted here. Rejected entirely
 * (422) unless the route-bound version is currently draft — same
 * "checked in withValidator() against the route-bound record's current
 * status" pattern UpdateLifecycleProcessRequest/UpdateLifecycleTaskRequest
 * already established (see docs/architecture.md), not something
 * expressible as route middleware.
 */
class UpdateHrDocumentTemplateVersionRequest extends FormRequest
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var HrDocumentTemplateVersion $version */
            $version = $this->route('hrDocumentTemplateVersion');

            if ($version->status !== HrDocumentTemplateVersionStatus::Draft) {
                $validator->errors()->add('content_template', 'Only a draft version can be edited.');
            }
        });
    }
}
