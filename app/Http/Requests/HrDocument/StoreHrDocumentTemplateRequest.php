<?php

namespace App\Http\Requests\HrDocument;

use App\Enums\HrDocumentTemplateStatus;
use App\Enums\HrDocumentType;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreHrDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Auto-generate slug from title if not explicitly provided, before
     * validation runs, so the uniqueness rule below validates the final
     * value either way — same pattern as StoreDocumentCategoryRequest.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('title')) {
            $this->merge(['slug' => Str::slug($this->input('title'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => [
                'required', 'string', 'max:255',
                Rule::unique('hr_document_templates', 'title')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('hr_document_templates', 'slug')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'document_type' => ['required', new Enum(HrDocumentType::class)],
            // Not a column on hr_document_templates itself (Checkpoint 36
            // moved content to HrDocumentTemplateVersion) — the controller
            // uses this to create the template's first (published) version
            // in the same request, preserving the single-step create UX
            // approved for this checkpoint. Plain text/markdown-like only,
            // no HTML rendering — see App\Services\HrDocuments\PlaceholderRenderer.
            'content_template' => ['required', 'string', 'max:20000'],
            'status' => ['nullable', new Enum(HrDocumentTemplateStatus::class)],
        ];
    }
}
