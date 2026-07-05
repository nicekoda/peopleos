<?php

namespace App\Http\Requests\Department;

use App\Enums\DepartmentStatus;
use App\Models\Department;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Checkpoint 32 — name/description/status only. Slug is never
 * reassigned here regardless of what request body arrives — the
 * controller never assigns it from this request's validated data at
 * all, so a department's slug never changes after creation through
 * this endpoint.
 */
class UpdateDepartmentRequest extends FormRequest
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
        /** @var Department $department */
        $department = $this->route('department');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('departments', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($department->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(DepartmentStatus::class)],
        ];
    }
}
