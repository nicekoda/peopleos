<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Checkpoint 53 — deliberately narrow: the role's id/name only, never
 * the role's own permission set. Never returns `custom_field_definition_id`
 * (redundant — this is always nested under the definition it belongs
 * to) or `created_by`/`updated_by`.
 */
class CustomFieldVisibilityRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => [
                'id' => $this->role->id,
                'name' => $this->role->name,
            ],
            'can_view' => $this->can_view,
            'can_edit' => $this->can_edit,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
