<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresCustomFieldEntityModuleEnabled;
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
 *
 * Checkpoint 51 — module enforcement moved here from a hardcoded
 * `module:recruitment` route middleware, which was only ever correct
 * because both entities that existed at the time (recruitment_applicant,
 * job_application) belong to Recruitment. `CustomFieldEntity::requiredModule()`
 * is the real per-entity source of truth now (null for Employee, a core/
 * never-toggleable module) — every future entity declares its own
 * requirement there instead of a route-level guess. Same 403 response
 * shape `EnsureModuleEnabled` already produces, so existing frontend
 * error handling for a disabled module needs no changes.
 */
class CustomFieldDefinitionController extends Controller
{
    use EnsuresCustomFieldEntityModuleEnabled;

    public function index(Request $request, string $entityType): AnonymousResourceCollection
    {
        $entity = $this->resolveEntityType($entityType);
        $this->ensureModuleEnabled($entity);

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
        $this->ensureModuleEnabled($entity);
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
        $this->ensureModuleEnabled($customFieldDefinition->entity_type);

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

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(CustomFieldDefinition $definition): void
    {
        abort_unless($definition->tenant_id === app(Tenant::class)->id, 404);
    }
}
