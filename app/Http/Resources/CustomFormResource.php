<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Checkpoint 52 — deliberately never returns `tenant_id`/`created_by`/
 * `updated_by`, same posture as CustomFieldDefinitionResource. Returns
 * both active and inactive forms/sections (Settings > Custom Forms
 * needs to manage disabled ones) — the entity-page renderer filters to
 * `status === 'active'` client-side, the same split CustomFieldsCard
 * already has for definitions. Field-level omission (disabled custom
 * field, or viewer lacks can_view) is enforced inside
 * CustomFormSectionResource, not here.
 */
class CustomFormResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type->value,
            'form_key' => $this->form_key,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            'sections' => CustomFormSectionResource::collection($this->whenLoaded('sections')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
