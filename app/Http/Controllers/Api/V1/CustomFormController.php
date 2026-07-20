<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresCustomFieldEntityModuleEnabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomForms\StoreCustomFormRequest;
use App\Http\Requests\CustomForms\UpdateCustomFormRequest;
use App\Http\Resources\CustomFormResource;
use App\Models\CustomForm;
use App\Models\Tenant;
use App\Services\CustomForms\CustomFormDefinitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Checkpoint 52. `$entityType` is a plain string route parameter, not
 * enum-bound — same non-defensive-enum-route-param posture as
 * CustomFieldDefinitionController. Forms are never hard-deleted, only
 * enabled/disabled via `update()`'s `status` field. Returns both active
 * and inactive forms (Settings > Custom Forms needs to manage disabled
 * ones) — the entity-page renderer filters to `status === 'active'`
 * client-side, the same split CustomFieldsCard already has for
 * definitions.
 */
class CustomFormController extends Controller
{
    use EnsuresCustomFieldEntityModuleEnabled;

    public function index(Request $request, string $entityType): AnonymousResourceCollection
    {
        $entity = $this->resolveEntityType($entityType);
        $this->ensureModuleEnabled($entity);

        $forms = CustomForm::query()
            ->where('tenant_id', app(Tenant::class)->id)
            ->where('entity_type', $entity->value)
            ->with(['sections.fields.customFieldDefinition'])
            ->orderBy('sort_order')
            ->get();

        return CustomFormResource::collection($forms);
    }

    public function store(StoreCustomFormRequest $request, string $entityType, CustomFormDefinitionService $service): JsonResponse
    {
        $entity = $this->resolveEntityType($entityType);
        $this->ensureModuleEnabled($entity);

        $form = $service->createForm(
            tenant: app(Tenant::class),
            entityType: $entity,
            data: $request->validated(),
            actor: $request->user(),
        );

        return (new CustomFormResource($form->load('sections.fields.customFieldDefinition')))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomFormRequest $request, CustomForm $customForm, CustomFormDefinitionService $service): CustomFormResource
    {
        $this->ensureBelongsToCurrentTenant($customForm);
        $this->ensureModuleEnabled($customForm->entity_type);

        $form = $service->updateForm($customForm, $request->validated(), $request->user());

        return new CustomFormResource($form->load('sections.fields.customFieldDefinition'));
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(CustomForm $form): void
    {
        abort_unless($form->tenant_id === app(Tenant::class)->id, 404);
    }
}
