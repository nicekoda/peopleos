<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Checkpoint 52 — embeds the field's own CustomFieldDefinition (via
 * CustomFieldDefinitionResource, reused as-is — never a second
 * definition shape). Effective label/required are left for the
 * frontend to compute (label_override ?? custom_field_definition.label,
 * is_required_override ?? custom_field_definition.is_required) rather
 * than pre-computed here, keeping this a plain passthrough of both
 * layers rather than inventing a third "resolved" shape.
 */
class CustomFormFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label_override' => $this->label_override,
            'help_text' => $this->help_text,
            'placeholder' => $this->placeholder,
            // UI-only (Checkpoint 52 decision 9) — never consulted by
            // CustomFieldValueValidator.
            'is_required_override' => $this->is_required_override,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'custom_field_definition' => new CustomFieldDefinitionResource($this->whenLoaded('customFieldDefinition')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
