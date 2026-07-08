<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\ApplicationStage;
use App\Enums\DepartmentStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Enums\LocationStatus;
use App\Enums\PositionStatus;
use App\Models\RecruitmentApplication;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * The manual/override fields for candidate-to-employee conversion —
 * identical field-level rules to StoreEmployeeRequest (same
 * uniqueness/active-lookup checks), since conversion creates a real
 * Employee row and must never be a looser path than a normal create.
 * tenant_id/created_by/updated_by/converted_employee_id/converted_at/
 * converted_by/manager_employee_id are deliberately absent — the first
 * six are always controller-set, and manager assignment stays the
 * exclusive job of PATCH /employees/{id}/manager (see
 * AssignManagerRequest), never accepted at creation or conversion time.
 */
class ConvertApplicationToEmployeeRequest extends FormRequest
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
            'employee_number' => [
                'required', 'string', 'max:255',
                Rule::unique('employees', 'employee_number')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'work_email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('employees', 'work_email')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'start_date' => ['nullable', 'date'],
            'status' => ['nullable', new Enum(EmployeeStatus::class)],
            'employment_type' => ['required', new Enum(EmploymentType::class)],
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
        ];
    }

    /**
     * Eligibility is about the application's own state, not the
     * submitted fields — checked here (same "read-only precondition
     * blocks the whole request" shape as StoreLifecycleTaskRequest
     * rejecting new tasks on a terminal process) rather than in the
     * controller, so an ineligible attempt never even reaches employee
     * creation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var RecruitmentApplication $application */
            $application = $this->route('jobApplication');

            if ($application->converted_employee_id !== null) {
                $validator->errors()->add('application', 'This application has already been converted to an employee.');

                return;
            }

            if ($application->stage !== ApplicationStage::Hired || ! $application->ready_for_conversion) {
                $validator->errors()->add('application', 'This application must be at the hired stage and marked ready for conversion before it can be converted.');
            }
        });
    }
}
