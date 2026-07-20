<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresCustomFieldEntityModuleEnabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomForms\StoreCustomFormSectionRequest;
use App\Http\Requests\CustomForms\UpdateCustomFormSectionRequest;
use App\Http\Resources\CustomFormSectionResource;
use App\Models\CustomForm;
use App\Models\CustomFormSection;
use App\Models\Tenant;
use App\Services\CustomForms\CustomFormDefinitionService;
use Illuminate\Http\JsonResponse;

class CustomFormSectionController extends Controller
{
    use EnsuresCustomFieldEntityModuleEnabled;

    public function store(StoreCustomFormSectionRequest $request, CustomForm $customForm, CustomFormDefinitionService $service): JsonResponse
    {
        $this->ensureFormBelongsToCurrentTenant($customForm);
        $this->ensureModuleEnabled($customForm->entity_type);

        $section = $service->createSection($customForm, $request->validated(), $request->user());

        return (new CustomFormSectionResource($section->load('fields.customFieldDefinition')))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomFormSectionRequest $request, CustomFormSection $customFormSection, CustomFormDefinitionService $service): CustomFormSectionResource
    {
        $this->ensureFormBelongsToCurrentTenant($customFormSection->form);
        $this->ensureModuleEnabled($customFormSection->form->entity_type);

        $section = $service->updateSection($customFormSection, $request->validated(), $request->user());

        return new CustomFormSectionResource($section->load('fields.customFieldDefinition'));
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope on
     * CustomForm — CustomFormSection has no tenant_id of its own, so
     * this walks up to its parent form, same posture as every other
     * controller in this app. 404, not 403.
     */
    private function ensureFormBelongsToCurrentTenant(CustomForm $form): void
    {
        abort_unless($form->tenant_id === app(Tenant::class)->id, 404);
    }
}
