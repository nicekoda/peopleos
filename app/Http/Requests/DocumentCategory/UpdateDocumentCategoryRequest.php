<?php

namespace App\Http\Requests\DocumentCategory;

use App\Enums\DocumentAppliesTo;
use App\Enums\DocumentCategoryStatus;
use App\Models\DocumentCategory;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateDocumentCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH semantics: every field optional. Unlike create, slug is never
     * auto-regenerated from a changed name here — an existing slug is a
     * stable identifier future documents/references may rely on; it only
     * changes if explicitly provided.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;
        /** @var DocumentCategory $category */
        $category = $this->route('documentCategory');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('document_categories', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($category->id),
            ],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('document_categories', 'slug')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($category->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'applies_to' => ['sometimes', new Enum(DocumentAppliesTo::class)],
            'is_sensitive' => ['sometimes', 'boolean'],
            'is_required' => ['sometimes', 'boolean'],
            'requires_expiry_date' => ['sometimes', 'boolean'],
            'status' => ['sometimes', new Enum(DocumentCategoryStatus::class)],
        ];
    }
}
