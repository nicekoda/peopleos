<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CustomFieldEntity;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomFields\StoreCustomFieldDefinitionRequest;
use App\Http\Requests\CustomFields\UpdateCustomFieldDefinitionRequest;
use App\Http\Resources\CustomFieldDefinitionResource;
use App\Models\CustomFieldDefinition;
use App\Models\Tenant;
use App\Services\CustomFields\CustomFieldDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Checkpoint 48. `$entityType` is a plain string route parameter, not
 * enum-bound — an unknown value gets a clean 422 here, never a
 * route-model-binding 404, same posture TenantModuleController already
 * established for `$moduleKey`. Definitions are never hard-deleted —
 * only enabled/disabled via `update()`'s `status` field (decision 12).
 */
class CustomFieldDefinitionController extends Controller
{
    public function index(Request $request, string $entityType): AnonymousResourceCollection
    {
        $entity = $this->resolveEntityType($entityType);

        $definitions = CustomFieldDefinition::query()
            ->where('entity_type', $entity->value)
            ->with(['options', 'validationRules'])
            ->orderBy('sort_order')
            ->get();

        return CustomFieldDefinitionResource::collection($definitions);
    }

    public function store(StoreCustomFieldDefinitionRequest $request, string $entityType, CustomFieldDefinitionService $service): JsonResponse
    {
        $entity = $this->resolveEntityType($entityType);
        $validated = $request->validated();

        $definition = $service->create(
            tenant: app(Tenant::class),
            entityType: $entity,
            data: $validated,
            options: $validated['options'] ?? [],
            validationRules: $validated['validation_rules'] ?? [],
            actor: $request->user(),
        );

        return (new CustomFieldDefinitionResource($definition))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomFieldDefinitionRequest $request, CustomFieldDefinition $customFieldDefinition, CustomFieldDefinitionService $service): CustomFieldDefinitionResource
    {
        $this->ensureBelongsToCurrentTenant($customFieldDefinition);

        $validated = $request->validated();

        $definition = $service->update(
            definition: $customFieldDefinition,
            data: $validated,
            options: array_key_exists('options', $validated) ? $validated['options'] : null,
            validationRules: array_key_exists('validation_rules', $validated) ? $validated['validation_rules'] : null,
            actor: $request->user(),
        );

        return new CustomFieldDefinitionResource($definition);
    }

    private function resolveEntityType(string $entityType): CustomFieldEntity
    {
        $entity = CustomFieldEntity::tryFrom($entityType);
        abort_if($entity === null, 422, 'Unknown or unsupported entity type.');

        return $entity;
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(CustomFieldDefinition $definition): void
    {
        abort_unless($definition->tenant_id === app(Tenant::class)->id, 404);
    }
}
