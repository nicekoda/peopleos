<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by`/
 * `deleted_at` — same convention as HrDocumentTemplateResource.
 * `generated_by`/`submitted_by`/`approved_by`/`rejected_by` ARE exposed
 * (unlike created_by/updated_by) because they're meaningful business
 * data — who took each approval-workflow action — not duplicate
 * administrative fields, the same distinction EmployeeDocumentResource
 * already draws for `uploaded_by`/`approved_by`. `rejection_reason` is
 * exposed too (Checkpoint 37) — the whole point of rejecting is that the
 * submitter/HR staff can see why; it's never included in audit logs
 * (see HrGeneratedDocumentController), but hiding it from the resource
 * itself would defeat the feature.
 */
class HrGeneratedDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->fullName(),
                'employee_number' => $this->employee->employee_number,
            ]),
            'hr_document_template_id' => $this->hr_document_template_id,
            'hr_document_template_version_id' => $this->hr_document_template_version_id,
            'employee_document_id' => $this->employee_document_id,
            'title' => $this->title,
            'document_type' => $this->document_type->value,
            'status' => $this->status->value,
            'rendered_content' => $this->rendered_content,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'generated_by' => $this->generated_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submitted_by' => $this->submitted_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejected_by' => $this->rejected_by,
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
