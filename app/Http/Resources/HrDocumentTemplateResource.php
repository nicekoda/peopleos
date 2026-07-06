<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by`/
 * `deleted_at` — same "narrow, internal fields excluded" convention as
 * DocumentCategoryResource. Tenant isolation is enforced by
 * BelongsToTenant + the controller's explicit ownership check, not by
 * anything in this resource. `content_template` moved to
 * HrDocumentTemplateVersion in Checkpoint 36 — see
 * `current_version_id`/`GET .../versions` instead.
 */
class HrDocumentTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'document_type' => $this->document_type->value,
            'status' => $this->status->value,
            'current_version_id' => $this->current_version_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
