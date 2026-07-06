<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HrDocumentTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HrDocument\StoreHrDocumentTemplateRequest;
use App\Http\Requests\HrDocument\UpdateHrDocumentTemplateRequest;
use App\Http\Resources\HrDocumentTemplateResource;
use App\Models\HrDocumentTemplate;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HrDocumentTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = HrDocumentTemplate::query()->orderBy('title')->paginate();

        return HrDocumentTemplateResource::collection($templates);
    }

    public function store(StoreHrDocumentTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['status'] ??= HrDocumentTemplateStatus::Active->value;
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $template = HrDocumentTemplate::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template.created',
            module: 'hr_documents',
            tenantId: $template->tenant_id,
            auditableType: HrDocumentTemplate::class,
            auditableId: $template->id,
            description: "HR document template '{$template->title}' created.",
            newValues: $template->only(['title', 'slug', 'document_type', 'status']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new HrDocumentTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(Request $request, HrDocumentTemplate $hrDocumentTemplate): HrDocumentTemplateResource
    {
        $this->ensureBelongsToCurrentTenant($hrDocumentTemplate);

        return new HrDocumentTemplateResource($hrDocumentTemplate);
    }

    public function update(UpdateHrDocumentTemplateRequest $request, HrDocumentTemplate $hrDocumentTemplate): HrDocumentTemplateResource
    {
        $this->ensureBelongsToCurrentTenant($hrDocumentTemplate);

        $originalValues = $hrDocumentTemplate->getOriginal();

        $hrDocumentTemplate->fill($request->validated());
        $hrDocumentTemplate->updated_by = $request->user()->id;
        $hrDocumentTemplate->save();

        $changes = $hrDocumentTemplate->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'hr_document_template.updated',
                module: 'hr_documents',
                tenantId: $hrDocumentTemplate->tenant_id,
                auditableType: HrDocumentTemplate::class,
                auditableId: $hrDocumentTemplate->id,
                description: "HR document template '{$hrDocumentTemplate->title}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new HrDocumentTemplateResource($hrDocumentTemplate);
    }

    /**
     * Soft delete only ("archive") — a template referenced by existing
     * generated documents is always safe to archive: hr_generated_documents.
     * hr_document_template_id is nullOnDelete, and GenerateHrDocumentRequest
     * already excludes inactive/soft-deleted templates from new
     * generation, so the template simply becomes unavailable for *new*
     * letters without affecting any already generated. Same pattern as
     * DocumentCategoryController::destroy().
     */
    public function destroy(Request $request, HrDocumentTemplate $hrDocumentTemplate): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($hrDocumentTemplate);

        $snapshot = $hrDocumentTemplate->only(['title', 'slug', 'status']);

        $hrDocumentTemplate->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template.archived',
            module: 'hr_documents',
            tenantId: $hrDocumentTemplate->tenant_id,
            auditableType: HrDocumentTemplate::class,
            auditableId: $hrDocumentTemplate->id,
            description: "HR document template '{$hrDocumentTemplate->title}' archived.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'HR document template archived.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403: don't
     * reveal that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(HrDocumentTemplate $hrDocumentTemplate): void
    {
        abort_unless($hrDocumentTemplate->tenant_id === app(Tenant::class)->id, 404);
    }
}
