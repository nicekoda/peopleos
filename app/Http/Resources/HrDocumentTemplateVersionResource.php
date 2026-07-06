<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by`/
 * `deleted_at` — same convention as HrDocumentTemplateResource.
 * `published_by` IS exposed as a plain user ID (not hidden) — meaningful
 * business data (who published this wording), the same distinction
 * HrGeneratedDocumentResource already draws for `generated_by`.
 */
class HrDocumentTemplateVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hr_document_template_id' => $this->hr_document_template_id,
            'version_number' => $this->version_number,
            'content_template' => $this->content_template,
            'status' => $this->status->value,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->published_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
