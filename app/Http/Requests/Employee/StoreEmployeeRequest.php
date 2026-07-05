<?php

namespace App\Http\Requests\Employee;

use App\Enums\DepartmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\LocationStatus;
use App\Enums\PositionStatus;
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
            // Checkpoint 32 — excludes archived (status: inactive) and
            // soft-deleted rows, same pattern as
            // StoreEmployeeDocumentRequest's document_category_id
            // (Checkpoint 9): Rule::exists() is a raw DB check that
            // bypasses Eloquent's SoftDeletes scope entirely, so
            // deleted_at/status must be checked explicitly here. An
            // employee must never be assignable to an archived
            // department/position/location going forward.
            'department_id' => [
                'nullable', 'string',
                Rule::exists('departments', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', DepartmentStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            'location_id' => [
                'nullable', 'string',
                Rule::exists('locations', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', LocationStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            'position_id' => [
                'nullable', 'string',
                Rule::exists('positions', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', PositionStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
            // manager_employee_id is deliberately not a validated field
            // here (Checkpoint 13) — even at creation time, a manager can
            // only be assigned through the dedicated
            // PATCH /employees/{employee}/manager endpoint, which runs
            // the full tenant/status/cycle validation this endpoint
            // never did. A new employee always starts with no manager;
            // assigning one is a deliberate follow-up call. See
            // docs/security.md.
            'start_date' => ['nullable', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'confirmation_date' => ['nullable', 'date'],
        ];
    }
}
