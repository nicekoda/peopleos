<?php

namespace App\Http\Requests\Recruitment;

use App\Enums\EmploymentType;
use App\Enums\RecruitmentJobStatus;
use App\Models\RecruitmentJob;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * created_by/updated_by/opened_at/closed_at stay controller-only —
     * opened_at/closed_at are derived from the status transition (see
     * JobOpeningController::update()), not request input.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(Tenant::class)->id;

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
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
            'status' => ['sometimes', new Enum(RecruitmentJobStatus::class)],
        ];
    }

    /**
     * A requested status change must be a legal transition from the
     * job's *current* status — mirrors UpdateLifecycleProcessRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('status')) {
                return;
            }

            /** @var RecruitmentJob $job */
            $job = $this->route('jobOpening');
            $requested = RecruitmentJobStatus::from($this->input('status'));

            if (! $job->status->canTransitionTo($requested)) {
                $validator->errors()->add('status', "Cannot transition from {$job->status->value} to {$requested->value}.");
            }
        });
    }
}
