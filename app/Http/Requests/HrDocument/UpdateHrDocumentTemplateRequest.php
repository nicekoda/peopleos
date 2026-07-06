<?php

namespace App\Http\Requests\HrDocument;

use App\Enums\HrDocumentTemplateStatus;
use App\Enums\HrDocumentType;
use App\Models\HrDocumentTemplate;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateHrDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH semantics: every field optional. Slug is never
     * auto-regenerated from a changed title here — an existing slug is a
     * stable identifier; it only changes if explicitly provided. Same
     * pattern as UpdateDocumentCategoryRequest. No `content_template`
     * field — content moved to HrDocumentTemplateVersion in Checkpoint
     * 36; use POST .../versions and PATCH .../hr-document-template-versions/{id}
     * to change wording.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;
        /** @var HrDocumentTemplate $template */
        $template = $this->route('hrDocumentTemplate');

        return [
            'title' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('hr_document_templates', 'title')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($template->id),
            ],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('hr_document_templates', 'slug')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($template->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'document_type' => ['sometimes', new Enum(HrDocumentType::class)],
            'status' => ['sometimes', new Enum(HrDocumentTemplateStatus::class)],
        ];
    }
}
