<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreEmployeeRequest extends FormRequest
{
    /**
     * Authorization (the employees.create permission check) is handled by
     * route middleware, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * tenant_id is deliberately not a validated field — it can never be
     * set from the request body. It's always taken from the
     * server-resolved Tenant in the controller.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'employee_number' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_number')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'work_email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('employees', 'work_email')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', new Enum(EmployeeStatus::class)],
            'employment_type' => ['required', new Enum(EmploymentType::class)],
            'department_id' => [
                'nullable', 'string',
                Rule::exists('departments', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'location_id' => [
                'nullable', 'string',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'position_id' => [
                'nullable', 'string',
                Rule::exists('positions', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'manager_employee_id' => [
                'nullable', 'string',
                Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'start_date' => ['nullable', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'confirmation_date' => ['nullable', 'date'],
        ];
    }
}
