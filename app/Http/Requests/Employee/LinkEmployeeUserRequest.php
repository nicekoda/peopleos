<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LinkEmployeeUserRequest extends FormRequest
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
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('is_platform_admin', false)
                    ->where('status', User::STATUS_ACTIVE)),
            ],
        ];
    }

    /**
     * Checks against the route-bound employee (not expressible as a
     * simple field rule): must not already be linked, must not be
     * terminated. The user-not-already-linked-elsewhere case is caught
     * by the users.user_id unique constraint at the database level — this
     * just gives a clean validation error instead of a raw DB exception.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Employee $employee */
            $employee = $this->route('employee');
            $userId = $this->input('user_id');

            if ($employee->user_id !== null) {
                $validator->errors()->add('user_id', 'This employee is already linked to a user. Unlink first.');

                return;
            }

            if ($employee->status === EmployeeStatus::Terminated) {
                $validator->errors()->add('user_id', 'A terminated employee cannot be linked.');

                return;
            }

            if ($userId && Employee::query()->where('user_id', $userId)->exists()) {
                $validator->errors()->add('user_id', 'This user is already linked to a different employee.');
            }
        });
    }
}
