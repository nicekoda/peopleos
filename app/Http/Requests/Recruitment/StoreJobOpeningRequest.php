<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\EmploymentType;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * status/opened_at/closed_at/created_by/updated_by are deliberately
     * absent — a new job opening always starts as draft, set by the
     * controller, same rule as StoreLifecycleProcessRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => ['required', 'string', 'max:255'],
            'department_id' => [
                'nullable', 'string',
                Rule::exists('departments', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'position_id' => [
                'nullable', 'string',
                Rule::exists('positions', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'location_id' => [
                'nullable', 'string',
                Rule::exists('locations', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'employment_type' => ['nullable', new Enum(EmploymentType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
