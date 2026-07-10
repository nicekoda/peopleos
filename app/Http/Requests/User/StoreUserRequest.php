<?php

namespace App\Http\Requests\User;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Checkpoint 43 — the first user-creation request in this app. role_id
 * uses the identical tenant- and scope-restricted Rule::exists() as
 * AssignUserRoleRequest (a tenant role, not a platform role, belonging to
 * the current tenant) — User::assignRole() independently re-checks the
 * same thing as a backstop, same layered-guard shape as role assignment
 * everywhere else. employee_id is optional: creating a user account
 * never requires linking it to an employee, but when given, it must
 * resolve to a same-tenant employee that isn't already linked and isn't
 * terminated — the same two preconditions LinkEmployeeUserRequest already
 * enforces for linking an *existing* user, checked here again since this
 * request creates and links in a single step.
 */
class StoreUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            // Globally unique, not tenant-scoped — matches the real
            // database constraint on users.email (see 0001_01_01
            // create_users_table migration; User is one of the two
            // models in this app, alongside Role, that predates
            // BelongsToTenant).
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role_id' => [
                'required', 'integer',
                Rule::exists('roles', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->where('is_platform_role', false)
                    ->whereNull('deleted_at')),
            ],
            'employee_id' => [
                'nullable', 'string',
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    /**
     * Employee-state checks (already linked / terminated) can't be
     * expressed as a simple field rule — identical logic to
     * LinkEmployeeUserRequest, duplicated rather than shared since the
     * two requests validate against a different route-bound model
     * (Employee there, none here — this one resolves the employee from
     * the employee_id *input*, not a route parameter).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $employeeId = $this->input('employee_id');

            if (! $employeeId) {
                return;
            }

            $employee = Employee::query()->find($employeeId);

            if ($employee === null) {
                // Already caught by the Rule::exists() above.
                return;
            }

            if ($employee->user_id !== null) {
                $validator->errors()->add('employee_id', 'This employee is already linked to a user.');

                return;
            }

            if ($employee->status === EmployeeStatus::Terminated) {
                $validator->errors()->add('employee_id', 'A terminated employee cannot be linked.');
            }
        });
    }
}
