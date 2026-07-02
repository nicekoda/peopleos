<?php

namespace App\Http\Requests\Policy;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignPolicyRequest extends FormRequest
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
        $tenantId = app(Tenant::class)->id;

        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => [
                'string', 'distinct',
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
