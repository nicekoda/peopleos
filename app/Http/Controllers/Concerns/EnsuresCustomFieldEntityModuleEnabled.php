<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\CustomFieldEntity;
use App\Services\TenantModuleService;

/**
 * Checkpoint 52 — extracted from CustomFieldDefinitionController (which
 * introduced this runtime check in Checkpoint 51, replacing a hardcoded
 * module:recruitment route gate) so CustomFormController and friends
 * reuse the exact same enforcement rather than a second copy of it —
 * the whole point of CustomFieldEntity::requiredModule() is one source
 * of truth per entity; this trait is the one place that source of
 * truth gets checked. resolveEntityType() is bundled alongside it since
 * every controller that needs the module check also needs to resolve
 * the entity type from a route parameter first, the same non-defensive-
 * enum-route-param technique TenantModuleController established.
 */
trait EnsuresCustomFieldEntityModuleEnabled
{
    private function resolveEntityType(string $entityType): CustomFieldEntity
    {
        $entity = CustomFieldEntity::tryFrom($entityType);
        abort_if($entity === null, 422, 'Unknown or unsupported entity type.');

        return $entity;
    }

    private function ensureModuleEnabled(CustomFieldEntity $entity): void
    {
        $module = $entity->requiredModule();

        if ($module !== null && ! app(TenantModuleService::class)->isEnabled($module)) {
            abort(response()->json([
                'message' => 'This module is not enabled for your organisation.',
                'reason' => 'module_disabled',
            ], 403));
        }
    }
}
