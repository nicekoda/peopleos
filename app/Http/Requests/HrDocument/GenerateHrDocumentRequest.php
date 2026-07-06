<?php

namespace App\Http\Requests\HrDocument;

use App\Enums\HrDocumentTemplateStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateHrDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * employee_id/hr_document_template_id are both scoped to the current
     * tenant via Rule::exists() — a same-tenant employee/template is
     * necessary but the controller still performs its own explicit
     * ownership check as defense in depth (same two-layer pattern as
     * every other tenant-scoped resource in this app). Only an *active*
     * template may be used to generate a new document.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'hr_document_template_id' => [
                'required', 'string',
                Rule::exists('hr_document_templates', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', HrDocumentTemplateStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            // Optional override — defaults to the template's own title if
            // omitted (set by the controller, not here).
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
