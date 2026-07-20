<?php

namespace App\Http\Resources;

use App\Enums\CustomFieldDefinitionStatus;
use App\Services\CustomFields\CustomFieldAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Checkpoint 52, decision 13 — a field whose underlying custom field is
 * disabled, or that the requesting user lacks can_view for, is omitted
 * from `fields` entirely here (never a null-but-present entry, never
 * the raw value) — the exact same "omit inaccessible from the
 * response" posture CustomFieldValueService::getActiveValuesFor()
 * already enforces for values. A section's own active/inactive status
 * is NOT filtered here — Settings > Custom Forms needs to see and
 * manage disabled sections; the entity-page renderer filters those out
 * client-side, the same split responsibility CustomFieldsCard already
 * has for custom field definitions.
 */
class CustomFormSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        $visibleFields = $this->whenLoaded('fields', function () use ($user) {
            return $this->fields
                ->filter(function ($field) use ($user) {
                    $definition = $field->customFieldDefinition;

                    if ($definition->status !== CustomFieldDefinitionStatus::Active) {
                        return false;
                    }

                    return CustomFieldAccessResolver::resolve($definition, $user)['can_view'];
                })
                ->values();
        });

        return [
            'id' => $this->id,
            'section_key' => $this->section_key,
            'title' => $this->title,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'fields' => CustomFormFieldResource::collection($visibleFields),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
