<?php

namespace App\Http\Resources;

use App\Services\CustomFields\CustomFieldAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately never returns `tenant_id`/`created_by`/`updated_by` —
 * same posture as DocumentCategoryResource. `options`/`validation_rules`
 * are always included regardless of the definition's own status, so
 * Settings can show a disabled field's full configuration (decision 12)
 * — hiding disabled *definitions* only happens on the entity's own
 * form, not here.
 *
 * Checkpoint 50 — `can_view`/`can_edit` are computed fresh per request
 * against the *current caller*, combining both halves of the layering
 * principle: the entity's own parent permission
 * (CustomFieldEntity::valueViewPermission()/valueUpdatePermission())
 * and the field's own sensitivity-tier permission
 * (CustomFieldSensitivity::requiredAccessPermission()). Never a stored
 * value, never cached — this is pure UX metadata for the frontend to
 * decide what to render; the backend enforcement that actually matters
 * lives in CustomFieldValueService, not here. Checkpoint 52 — this
 * computation was extracted into CustomFieldAccessResolver so
 * CustomFormResource can reuse the exact same logic rather than
 * re-deriving it.
 */
/**
 * Checkpoint 53 — can_view/can_edit above now also reflect any active
 * configurable visibility rule matching the caller's roles
 * (CustomFieldAccessResolver itself was extended; nothing changed
 * here). visibility_rules lists every rule regardless of status,
 * gated by the same custom_fields.view permission that already gates
 * reaching this resource at all — there is no separate "management
 * only" response shape anywhere in this subsystem. Never exposes
 * anything about a role beyond its id/name.
 */
class CustomFieldDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $access = CustomFieldAccessResolver::resolve($this->resource, $request->user());

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
            'can_view' => $access['can_view'],
            'can_edit' => $access['can_edit'],
            'options' => CustomFieldOptionResource::collection($this->whenLoaded('options')),
            'validation_rules' => $this->whenLoaded('validationRules', fn () => $this->validationRules->map(fn ($rule) => [
                'rule_key' => $rule->rule_key->value,
                'rule_value' => $rule->rule_value,
            ])),
            'visibility_rules' => CustomFieldVisibilityRuleResource::collection($this->whenLoaded('visibilityRules')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
