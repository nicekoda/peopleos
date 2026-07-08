<?php

namespace App\Http\Requests\Recruitment;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A single request creates both the applicant and the application in one
 * step (JobApplicationController::store()) — same "one-step create"
 * shape as HrDocumentTemplateController::store() creating a template and
 * its first version together. stage/status/ready_for_conversion/
 * created_by/updated_by are deliberately absent — a new application
 * always starts at stage=applied, status=active, ready_for_conversion=
 * false, set by the controller.
 */
class StoreJobApplicationRequest extends FormRequest
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
            'recruitment_job_id' => [
                'required', 'string',
                Rule::exists('recruitment_jobs', 'id')->where(fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')),
            ],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:255'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
