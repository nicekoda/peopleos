<?php

namespace App\Http\Requests\Document;

use App\Models\DocumentCategory;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreEmployeeDocumentRequest extends FormRequest
{
    /**
     * Maximum upload size in kilobytes (Laravel's File::max() unit).
     * Not specified in the original spec — 10MB chosen as a reasonable
     * default for HR documents (scans, PDFs). Easy to change.
     */
    public const MAX_FILE_SIZE_KB = 10 * 1024;

    /**
     * MIME types matching the allowed extensions (pdf, doc, docx, jpg,
     * jpeg, png). Validated against the actual detected file content via
     * Laravel's File rule, not the client-declared extension/MIME type —
     * this is the real defense against a renamed-executable attack, not
     * the extension allowlist alone.
     */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'file' => [
                'required',
                File::types(self::ALLOWED_EXTENSIONS)->max(self::MAX_FILE_SIZE_KB),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'document_category_id' => [
                'nullable', 'string',
                Rule::exists('document_categories', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $categoryId = $this->input('document_category_id');

            if (! $categoryId) {
                return;
            }

            $category = DocumentCategory::query()->find($categoryId);

            if ($category && $category->requires_expiry_date && ! $this->input('expiry_date')) {
                $validator->errors()->add('expiry_date', 'This document category requires an expiry date.');
            }
        });
    }
}
