<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by`/
 * `deleted_at` (Checkpoint 25, Refinement 1) — internal/administrative
 * fields with no use in the Document Categories admin UI. Nothing here
 * is a substitute for the tenant scoping already enforced by
 * `BelongsToTenant` + the controller's explicit ownership check; this
 * is just keeping the response narrow.
 */
class DocumentCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'applies_to' => $this->applies_to->value,
            'is_sensitive' => $this->is_sensitive,
            'is_required' => $this->is_required,
            'requires_expiry_date' => $this->requires_expiry_date,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
