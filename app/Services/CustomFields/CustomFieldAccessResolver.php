<?php

namespace App\Services\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Models\User;

/**
 * Checkpoint 52 — extracted from CustomFieldDefinitionResource so the
 * new CustomFormResource (Checkpoint 52) can compute the exact same
 * can_view/can_edit flags without re-deriving the layering logic a
 * second time. Combines both halves of the Checkpoint 50 layering
 * principle: the entity's own parent permission
 * (CustomFieldEntity::valueViewPermission()/valueUpdatePermission())
 * and the field's own sensitivity-tier permission
 * (CustomFieldSensitivity::requiredAccessPermission()). Always computed
 * fresh against the current caller, never stored/cached — this is UX
 * metadata only; the backend enforcement that actually matters lives in
 * CustomFieldValueService, not here.
 */
class CustomFieldAccessResolver
{
    /**
     * @return array{can_view: bool, can_edit: bool}
     */
    public static function resolve(CustomFieldDefinition $definition, ?User $user): array
    {
        $tierPermission = $definition->sensitivity->requiredAccessPermission();
        $hasTierAccess = $tierPermission === null || ($user?->hasPermission($tierPermission) ?? false);

        return [
            'can_view' => $hasTierAccess && ($user?->hasPermission($definition->entity_type->valueViewPermission()) ?? false),
            'can_edit' => $hasTierAccess && ($user?->hasPermission($definition->entity_type->valueUpdatePermission()) ?? false),
        ];
    }
}
