<?php

namespace App\Http\Requests\DocumentCategory;

use App\Enums\DocumentAppliesTo;
use App\Enums\DocumentCategoryStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreDocumentCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Auto-generate slug from name if not explicitly provided, before
     * validation runs, so the uniqueness rule below validates the final
     * value either way.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('document_categories', 'name')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('document_categories', 'slug')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'applies_to' => ['nullable', new Enum(DocumentAppliesTo::class)],
            'is_sensitive' => ['nullable', 'boolean'],
            'is_required' => ['nullable', 'boolean'],
            'requires_expiry_date' => ['nullable', 'boolean'],
            'status' => ['nullable', new Enum(DocumentCategoryStatus::class)],
        ];
    }
}
