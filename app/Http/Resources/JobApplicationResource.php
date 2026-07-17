<?php

namespace App\Http\Resources;

use App\Enums\CustomFieldEntity;
use App\Services\CustomFields\CustomFieldValueService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recruitment_job_id' => $this->recruitment_job_id,
            'job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'title' => $this->job->title,
                'status' => $this->job->status->value,
                // Exposed so the conversion form (Checkpoint 40) can
                // pre-fill department/position/location/employment_type
                // from the job opening — same fields JobOpeningResource
                // already exposes, nothing new or more sensitive.
                'department_id' => $this->job->department_id,
                'position_id' => $this->job->position_id,
                'location_id' => $this->job->location_id,
                'employment_type' => $this->job->employment_type?->value,
            ]),
            'applicant' => $this->whenLoaded('applicant', fn () => [
                'id' => $this->applicant->id,
                'first_name' => $this->applicant->first_name,
                'last_name' => $this->applicant->last_name,
                'email' => $this->applicant->email,
                'phone' => $this->applicant->phone,
                'source' => $this->applicant->source,
                // Checkpoint 48 — active custom fields only (field_key =>
                // value); a disabled field's preserved value is never
                // returned here (decision 12). Checkpoint 50 — a field
                // the viewer lacks tier access to is omitted the same
                // way, never a null-but-present key or the raw value.
                'custom_field_values' => app(CustomFieldValueService::class)->getActiveValuesFor(
                    $this->applicant->tenant_id,
                    CustomFieldEntity::RecruitmentApplicant,
                    $this->applicant->id,
                    $request->user(),
                ),
            ]),
            'stage' => $this->stage->value,
            'status' => $this->status->value,
            // Checkpoint 49 — this application's own active custom
            // field values (App\Models\RecruitmentApplication),
            // deliberately top-level and distinct from
            // applicant.custom_field_values above, which belongs to
            // the applicant (RecruitmentApplicant) instead — the two
            // are different entities with independently-defined fields.
            'custom_field_values' => app(CustomFieldValueService::class)->getActiveValuesFor(
                $this->tenant_id,
                CustomFieldEntity::JobApplication,
                $this->id,
                $request->user(),
            ),
            'resume_document_id' => $this->resume_document_id,
            'cover_letter' => $this->cover_letter,
            'ready_for_conversion' => $this->ready_for_conversion,
            'converted_employee_id' => $this->converted_employee_id,
            'converted_employee' => $this->whenLoaded('convertedEmployee', fn () => $this->convertedEmployee === null ? null : [
                'id' => $this->convertedEmployee->id,
                'full_name' => $this->convertedEmployee->fullName(),
                'employee_number' => $this->convertedEmployee->employee_number,
            ]),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'onboarding_process_id' => $this->onboarding_process_id,
            'onboarding_process' => $this->whenLoaded('onboardingProcess', fn () => $this->onboardingProcess === null ? null : [
                'id' => $this->onboardingProcess->id,
                'status' => $this->onboardingProcess->status->value,
            ]),
            'notes' => RecruitmentApplicationNoteResource::collection($this->whenLoaded('notes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
