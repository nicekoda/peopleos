<?php

namespace App\Http\Resources;

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
            ]),
            'stage' => $this->stage->value,
            'status' => $this->status->value,
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
            'notes' => RecruitmentApplicationNoteResource::collection($this->whenLoaded('notes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
