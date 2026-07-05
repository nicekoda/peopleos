<?php

namespace App\Http\Requests\Employee;

use App\Enums\DepartmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\LocationStatus;
use App\Enums\PositionStatus;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateEmployeeRequest extends FormRequest
{
    /**
     * Authorization (the employees.update permission check, and the
     * tenant-ownership check on the bound employee) is handled by route
     * middleware and the controller, not here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * PATCH semantics: every field is optional (only provided fields are
     * validated/updated), but a provided value must still be valid.
     * tenant_id is deliberately not a validated field.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'employee_number' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_number')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($employee->id),
            ],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'work_email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('employees', 'work_email')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($employee->id),
            ],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', new Enum(EmployeeStatus::class)],
            'employment_type' => ['sometimes', 'required', new Enum(EmploymentType::class)],
            // Checkpoint 32 — excludes archived (status: inactive) and
            // soft-deleted rows, same pattern as
            // StoreEmployeeDocumentRequest's document_category_id
            // (Checkpoint 9). Only enforced when the field is actually
            // provided in the request body — an employee already linked
            // to a department that's later archived keeps that link
            // untouched by unrelated PATCH requests (e.g. updating just
            // first_name); this only blocks actively (re)assigning an
            // employee to an archived department/position/location.
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
            // here (Checkpoint 13) — this endpoint's old, weaker
            // validation (no cycle check, no active-status check, and a
            // raw Rule::exists() that didn't exclude soft-deleted
            // managers) is structurally closed off, not just
            // superseded. Every manager change must go through
            // PATCH/DELETE /employees/{employee}/manager, which runs
            // the full check (AssignManagerRequest +
            // ManagerHierarchyService). A stray manager_employee_id in
            // this endpoint's request body is silently ignored, the
            // same "not a validated field" pattern already used for
            // tenant_id/user_id elsewhere. See docs/security.md.
            'start_date' => ['nullable', 'date'],
            'probation_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'confirmation_date' => ['nullable', 'date'],
        ];
    }
}
