<?php

namespace App\Http\Requests\Policy;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePolicyVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A new version is always created as a draft — publishing is a
     * separate action/endpoint — so content/document are not required
     * here (they're required at publish time instead; see
     * PublishPolicyRequest).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string'],
            'employee_document_id' => [
                'nullable', 'string',
                Rule::exists('employee_documents', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ];
    }
}
