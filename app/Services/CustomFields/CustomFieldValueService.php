<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldDefinitionStatus;
use App\Enums\CustomFieldEntity;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checkpoint 48 — the only place `custom_field_values` rows are read or
 * written. No broad top-level values API exists; this is always called
 * from the owning entity's own controller (decision 17), after that
 * controller's own permission/tenant checks have already run. Every
 * write here still independently re-verifies: the definition belongs to
 * the same tenant as the entity, the definition's entity_type matches
 * the entity being written to, and the field is active before accepting
 * a new value for it — defense in depth, not a replacement for the
 * caller's own checks.
 *
 * Checkpoint 53 — field-level access (both read-omission and write-403)
 * is now decided exclusively by `CustomFieldAccessResolver::resolve()`,
 * replacing a previous private `hasTierAccess()` that only ever checked
 * the fixed sensitivity tier and knew nothing about configurable
 * visibility rules. There is now exactly one implementation of "can this
 * user view/edit this field" in the entire application — this class no
 * longer has its own copy of that logic.
 */
class CustomFieldValueService
{
    /**
     * Active field values for an entity, keyed by field_key — disabled
     * fields are never returned here (decision 12: "disabled fields are
     * not returned/editable in normal forms"). Values stored against a
     * now-disabled field are preserved in the database but simply
     * excluded from this read path.
     *
     * Checkpoint 50 — a field the viewer lacks tier access to is
     * omitted from the map the same way a disabled field already is
     * (never a null-but-present key, never the raw value) — this is
     * the one enforcement point every current and future entity's read
     * path shares, so no controller/Resource needs its own check.
     *
     * @return array<string, mixed>
     */
    public function getActiveValuesFor(string $tenantId, CustomFieldEntity $entityType, string $entityId, User $viewer): array
    {
        $definitions = $this->activeDefinitions($tenantId, $entityType);

        $values = CustomFieldValue::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType->value)
            ->where('entity_id', $entityId)
            ->whereIn('custom_field_definition_id', $definitions->pluck('id'))
            ->get()
            ->keyBy('custom_field_definition_id');

        $result = [];

        foreach ($definitions as $definition) {
            if (! CustomFieldAccessResolver::resolve($definition, $viewer)['can_view']) {
                continue;
            }

            $row = $values->get($definition->id);
            $result[$definition->field_key] = $row === null ? null : $row->{$definition->field_type->storageColumn()};
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $rawValues  field_key => raw value, sparse (only submitted keys)
     * @return array<string, array{previous: mixed, new: mixed, definition: CustomFieldDefinition}> changed fields only
     */
    public function setValuesFor(string $tenantId, CustomFieldEntity $entityType, string $entityId, array $rawValues, User $actor): array
    {
        if ($rawValues === []) {
            return [];
        }

        $definitions = $this->activeDefinitions($tenantId, $entityType)->keyBy('field_key');
        $changes = [];

        foreach ($rawValues as $fieldKey => $rawValue) {
            $definition = $definitions->get($fieldKey);

            if ($definition === null) {
                throw ValidationException::withMessages([
                    "custom_field_values.{$fieldKey}" => ["'{$fieldKey}' is not an active custom field for this entity."],
                ]);
            }

            // Checkpoint 50 — an authorization failure, not a validation
            // one: 403, not 422. Submitting a field_key the caller lacks
            // access to (by tier, or by Checkpoint 53's configurable
            // visibility rules) is rejected outright, never silently
            // dropped — a user must not be able to write a value to a
            // field they could never even see.
            if (! CustomFieldAccessResolver::resolve($definition, $actor)['can_edit']) {
                abort(403, "You do not have permission to edit '{$fieldKey}'.");
            }

            $result = CustomFieldValueValidator::validate($definition, $rawValue, enforceActiveOptions: true);

            $row = CustomFieldValue::query()->firstOrNew([
                'tenant_id' => $tenantId,
                'entity_type' => $entityType->value,
                'entity_id' => $entityId,
                'custom_field_definition_id' => $definition->id,
            ]);

            $previous = $row->exists ? $row->{$result['column']} : null;

            if ($previous === $result['value']) {
                continue;
            }

            // Clear every value column before setting the one this
            // type actually uses — a prior field_type change (only ever
            // allowed while no values exist, see
            // CustomFieldDefinitionService::update()) could otherwise
            // leave a stale value in a column no longer read.
            $row->value_text = null;
            $row->value_number = null;
            $row->value_date = null;
            $row->value_boolean = null;
            $row->value_json = null;

            $row->{$result['column']} = $result['value'];
            $row->created_by ??= $actor->id;
            $row->updated_by = $actor->id;
            $row->save();

            $changes[$fieldKey] = ['previous' => $previous, 'new' => $result['value'], 'definition' => $definition];
        }

        foreach ($changes as $fieldKey => $change) {
            CustomFieldAuditEvents::valueUpdated(
                definition: $change['definition'],
                entityType: $entityType->value,
                entityId: $entityId,
                previousValue: $change['previous'],
                newValue: $change['new'],
                actor: $actor,
                tenantId: $tenantId,
            );
        }

        return $changes;
    }

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    private function activeDefinitions(string $tenantId, CustomFieldEntity $entityType): Collection
    {
        return CustomFieldDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType->value)
            ->where('status', CustomFieldDefinitionStatus::Active->value)
            ->with(['options', 'validationRules'])
            ->orderBy('sort_order')
            ->get();
    }
}
