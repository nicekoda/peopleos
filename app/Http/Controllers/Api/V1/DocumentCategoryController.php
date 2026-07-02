<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DocumentAppliesTo;
use App\Enums\DocumentCategoryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentCategory\StoreDocumentCategoryRequest;
use App\Http\Requests\DocumentCategory\UpdateDocumentCategoryRequest;
use App\Http\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentCategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = DocumentCategory::query()->orderBy('name')->paginate();

        return DocumentCategoryResource::collection($categories);
    }

    public function store(StoreDocumentCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        // Eloquent's create() doesn't backfill DB column defaults into the
        // in-memory model for attributes omitted from the insert array —
        // the row gets the schema default, but $category->applies_to
        // would stay null in memory (crashing the resource's ->value
        // access) unless set explicitly here. Found while testing this
        // checkpoint.
        $validated['status'] ??= DocumentCategoryStatus::Active->value;
        $validated['applies_to'] ??= DocumentAppliesTo::Employee->value;
        $validated['is_sensitive'] ??= false;
        $validated['is_required'] ??= false;
        $validated['requires_expiry_date'] ??= false;
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $category = DocumentCategory::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'document_category.created',
            module: 'documents',
            tenantId: $category->tenant_id,
            auditableType: DocumentCategory::class,
            auditableId: $category->id,
            description: "Document category '{$category->name}' created.",
            newValues: $category->only([
                'name', 'slug', 'description', 'applies_to', 'is_sensitive',
                'is_required', 'requires_expiry_date', 'status',
            ]),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new DocumentCategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Request $request, DocumentCategory $documentCategory): DocumentCategoryResource
    {
        $this->ensureBelongsToCurrentTenant($documentCategory);

        return new DocumentCategoryResource($documentCategory);
    }

    public function update(UpdateDocumentCategoryRequest $request, DocumentCategory $documentCategory): DocumentCategoryResource
    {
        $this->ensureBelongsToCurrentTenant($documentCategory);

        $originalValues = $documentCategory->getOriginal();

        $documentCategory->fill($request->validated());
        $documentCategory->updated_by = $request->user()->id;
        $documentCategory->save();

        $changes = $documentCategory->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'document_category.updated',
                module: 'documents',
                tenantId: $documentCategory->tenant_id,
                auditableType: DocumentCategory::class,
                auditableId: $documentCategory->id,
                description: "Document category '{$documentCategory->name}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new DocumentCategoryResource($documentCategory);
    }

    /**
     * Soft delete only — there is no hard-delete code path in this API at
     * all, so a category referenced by existing documents is always safe
     * to "delete" here: their document_category_id foreign keys are
     * untouched by a soft delete, and Rule::exists() checks used during
     * document upload already exclude inactive/soft-deleted categories
     * (see StoreEmployeeDocumentRequest), so the category simply becomes
     * unavailable for *new* uploads without affecting existing ones.
     */
    public function destroy(Request $request, DocumentCategory $documentCategory): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($documentCategory);

        $snapshot = $documentCategory->only(['name', 'slug', 'status']);

        $documentCategory->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'document_category.deleted',
            module: 'documents',
            tenantId: $documentCategory->tenant_id,
            auditableType: DocumentCategory::class,
            auditableId: $documentCategory->id,
            description: "Document category '{$documentCategory->name}' soft-deleted.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Document category deleted.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403: don't
     * reveal that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(DocumentCategory $documentCategory): void
    {
        abort_unless($documentCategory->tenant_id === app(Tenant::class)->id, 404);
    }
}
