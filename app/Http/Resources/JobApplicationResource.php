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
            'notes' => RecruitmentApplicationNoteResource::collection($this->whenLoaded('notes')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
