<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresCustomFieldEntityModuleEnabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomFields\StoreCustomFieldVisibilityRuleRequest;
use App\Http\Requests\CustomFields\UpdateCustomFieldVisibilityRuleRequest;
use App\Http\Resources\CustomFieldVisibilityRuleResource;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldVisibilityRule;
use App\Models\Tenant;
use App\Services\CustomFields\CustomFieldVisibilityRuleService;
use Illuminate\Http\JsonResponse;

/**
 * Checkpoint 53. Rules are never hard-deleted — only enabled/disabled
 * via `update()`'s `status` field, same posture as custom field
 * definitions/options and every custom-form row. No static
 * `module:{key}` gate — resolved at runtime from the definition's own
 * `entity_type`, the same `EnsuresCustomFieldEntityModuleEnabled` trait
 * `CustomFieldDefinitionController`/the form controllers already use.
 */
class CustomFieldVisibilityRuleController extends Controller
{
    use EnsuresCustomFieldEntityModuleEnabled;

    public function store(StoreCustomFieldVisibilityRuleRequest $request, CustomFieldDefinition $customFieldDefinition, CustomFieldVisibilityRuleService $service): JsonResponse
    {
        $this->ensureDefinitionBelongsToCurrentTenant($customFieldDefinition);
        $this->ensureModuleEnabled($customFieldDefinition->entity_type);

        $rule = $service->createRule($customFieldDefinition, $request->validated(), $request->user());

        return (new CustomFieldVisibilityRuleResource($rule->load('role')))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomFieldVisibilityRuleRequest $request, CustomFieldVisibilityRule $customFieldVisibilityRule, CustomFieldVisibilityRuleService $service): CustomFieldVisibilityRuleResource
    {
        $definition = $customFieldVisibilityRule->customFieldDefinition;
        $this->ensureDefinitionBelongsToCurrentTenant($definition);
        $this->ensureModuleEnabled($definition->entity_type);

        $rule = $service->updateRule($customFieldVisibilityRule, $request->validated(), $request->user());

        return new CustomFieldVisibilityRuleResource($rule->load('role'));
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope on
     * CustomFieldDefinition — CustomFieldVisibilityRule has no
     * tenant_id of its own, so this walks up to the owning definition.
     * 404, not 403.
     *
     * $definition is nullable here even though the relation is typed
     * non-null: BelongsToTenant's global scope applies to the relation
     * query too, so a rule whose definition belongs to a *different*
     * tenant than the one currently resolved silently resolves to null
     * rather than the real (other-tenant) definition. Must be checked
     * before the tenant-id comparison, or this throws a TypeError
     * instead of a clean 404 on a cross-tenant direct-ID request.
     */
    private function ensureDefinitionBelongsToCurrentTenant(?CustomFieldDefinition $definition): void
    {
        abort_if($definition === null, 404);
        abort_unless($definition->tenant_id === app(Tenant::class)->id, 404);
    }
}
