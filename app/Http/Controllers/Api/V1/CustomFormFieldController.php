<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\EnsuresCustomFieldEntityModuleEnabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomForms\StoreCustomFormFieldRequest;
use App\Http\Requests\CustomForms\UpdateCustomFormFieldRequest;
use App\Http\Resources\CustomFormFieldResource;
use App\Models\CustomForm;
use App\Models\CustomFormField;
use App\Models\CustomFormSection;
use App\Models\Tenant;
use App\Services\CustomForms\CustomFormDefinitionService;
use Illuminate\Http\JsonResponse;

class CustomFormFieldController extends Controller
{
    use EnsuresCustomFieldEntityModuleEnabled;

    public function store(StoreCustomFormFieldRequest $request, CustomFormSection $customFormSection, CustomFormDefinitionService $service): JsonResponse
    {
        $this->ensureFormBelongsToCurrentTenant($customFormSection->form);
        $this->ensureModuleEnabled($customFormSection->form->entity_type);

        $field = $service->createField($customFormSection, $request->validated(), $request->user());

        return (new CustomFormFieldResource($field->load('customFieldDefinition')))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomFormFieldRequest $request, CustomFormField $customFormField, CustomFormDefinitionService $service): CustomFormFieldResource
    {
        $this->ensureFormBelongsToCurrentTenant($customFormField->section->form);
        $this->ensureModuleEnabled($customFormField->section->form->entity_type);

        $field = $service->updateField($customFormField, $request->validated(), $request->user());

        return new CustomFormFieldResource($field->load('customFieldDefinition'));
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope on
     * CustomForm — neither CustomFormSection nor CustomFormField has
     * its own tenant_id, so this walks all the way up to the form. 404,
     * not 403.
     */
    private function ensureFormBelongsToCurrentTenant(CustomForm $form): void
    {
        abort_unless($form->tenant_id === app(Tenant::class)->id, 404);
    }
}
