<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by` —
 * same posture as DocumentCategoryResource. `options`/`validation_rules`
 * are always included regardless of the definition's own status, so
 * Settings can show a disabled field's full configuration (decision 12)
 * — hiding disabled *definitions* only happens on the entity's own
 * form, not here.
 */
class CustomFieldDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type->value,
            'field_key' => $this->field_key,
            'label' => $this->label,
            'description' => $this->description,
            'field_type' => $this->field_type->value,
            'is_required' => $this->is_required,
            'default_value' => $this->default_value,
            'sensitivity' => $this->sensitivity->value,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'options' => CustomFieldOptionResource::collection($this->whenLoaded('options')),
            'validation_rules' => $this->whenLoaded('validationRules', fn () => $this->validationRules->map(fn ($rule) => [
                'rule_key' => $rule->rule_key->value,
                'rule_value' => $rule->rule_value,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
