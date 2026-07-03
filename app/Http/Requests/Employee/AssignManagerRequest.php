<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\ManagerHierarchyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rule::exists() is a raw DB check that bypasses Eloquent's
     * SoftDeletes scope — status/deleted_at must be checked explicitly
     * here, the same fix already required by Checkpoint 9 (document
     * categories) and Checkpoint 12 (leave types). Only `active`
     * employees may be assigned as manager — the strictest safe
     * default, per your accepted plan; see docs/security.md.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'manager_employee_id' => [
                'required', 'string',
                Rule::exists('employees', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('status', EmployeeStatus::Active->value)
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    /**
     * Self-assignment and cycle detection — not expressible as a field
     * rule, since both need the route-bound employee and a resolved
     * Employee model for manager_employee_id.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $managerId = $this->input('manager_employee_id');

            if (! $managerId) {
                return;
            }

            /** @var Employee $employee */
            $employee = $this->route('employee');

            if ($managerId === $employee->id) {
                $validator->errors()->add('manager_employee_id', 'An employee cannot be their own manager.');

                return;
            }

            $manager = Employee::query()->find($managerId);

            if ($manager === null) {
                // Already caught by the exists rule above — nothing
                // further to check.
                return;
            }

            if (app(ManagerHierarchyService::class)->wouldCreateCycle($employee, $manager)) {
                $validator->errors()->add('manager_employee_id', 'This assignment would create a circular reporting relationship.');
            }
        });
    }
}
