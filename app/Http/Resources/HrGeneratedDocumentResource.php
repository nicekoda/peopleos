<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by`/
 * `deleted_at` — same convention as HrDocumentTemplateResource.
 * `generated_by` IS exposed (unlike created_by/updated_by) because it's
 * meaningful business data — who generated this letter — not a
 * duplicate administrative field, the same distinction EmployeeDocumentResource
 * already draws for `uploaded_by`/`approved_by`.
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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
