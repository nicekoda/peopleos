<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HrDocumentTemplateStatus;
use App\Enums\HrDocumentTemplateVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HrDocument\StoreHrDocumentTemplateRequest;
use App\Http\Requests\HrDocument\UpdateHrDocumentTemplateRequest;
use App\Http\Resources\HrDocumentTemplateResource;
use App\Models\HrDocumentTemplate;
use App\Models\HrDocumentTemplateVersion;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class HrDocumentTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = HrDocumentTemplate::query()->orderBy('title')->paginate();

        return HrDocumentTemplateResource::collection($templates);
    }

    /**
     * Checkpoint 36 — approved single-step create: this request still
     * accepts content_template (preserving the existing Create.tsx UX
     * unchanged) but it's no longer a column on hr_document_templates
     * itself; the controller creates the template row and its first,
     * already-published version together, in one transaction-free but
     * atomic-enough sequence (both writes happen before any response is
     * returned; a failure between them would leave an orphaned template
     * with no current_version_id, the same "active but unpublished" edge
     * case GenerateHrDocumentRequest already guards against).
     */
    public function store(StoreHrDocumentTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $contentTemplate = $validated['content_template'];
        unset($validated['content_template']);

        $validated['status'] ??= HrDocumentTemplateStatus::Active->value;
        $tenantId = app(Tenant::class)->id;
        $validated['tenant_id'] = $tenantId;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $template = HrDocumentTemplate::query()->create($validated);

        $version = HrDocumentTemplateVersion::query()->create([
            'tenant_id' => $tenantId,
            'hr_document_template_id' => $template->id,
            'version_number' => 1,
            'content_template' => $contentTemplate,
            'status' => HrDocumentTemplateVersionStatus::Published,
            'published_by' => $request->user()->id,
            'published_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $template->update(['current_version_id' => $version->id]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template.created',
            module: 'hr_documents',
            tenantId: $template->tenant_id,
            auditableType: HrDocumentTemplate::class,
            auditableId: $template->id,
            description: "HR document template '{$template->title}' created.",
            newValues: $template->only(['title', 'slug', 'document_type', 'status']),
            metadata: ['current_version_id' => $version->id, 'version_number' => 1],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new HrDocumentTemplateResource($template->fresh()))->response()->setStatusCode(201);
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
     * Checkpoint 38 — copies metadata (title with a "(Copy)" suffix,
     * description, document_type) and the source's *current published*
     * version's content_template into a brand-new template, immediately
     * published as version 1 — mirroring store()'s single-step
     * create-with-version-1 flow (your approved choice, not a draft
     * requiring a separate publish). Gated by hr_document_templates.create,
     * not a new permission — duplicating is just creating a new template
     * pre-filled from an existing one, the same trust level as a blank
     * create.
     */
    public function duplicate(Request $request, HrDocumentTemplate $hrDocumentTemplate): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($hrDocumentTemplate);

        $sourceVersion = $hrDocumentTemplate->currentVersion;
        abort_unless($sourceVersion !== null, 422, 'This template has no published version to duplicate.');

        $tenantId = $hrDocumentTemplate->tenant_id;
        [$title, $slug] = $this->uniqueDuplicateTitleAndSlug($hrDocumentTemplate, $tenantId);

        $duplicate = HrDocumentTemplate::query()->create([
            'tenant_id' => $tenantId,
            'title' => $title,
            'slug' => $slug,
            'description' => $hrDocumentTemplate->description,
            'document_type' => $hrDocumentTemplate->document_type,
            'status' => HrDocumentTemplateStatus::Active,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $version = HrDocumentTemplateVersion::query()->create([
            'tenant_id' => $tenantId,
            'hr_document_template_id' => $duplicate->id,
            'version_number' => 1,
            'content_template' => $sourceVersion->content_template,
            'status' => HrDocumentTemplateVersionStatus::Published,
            'published_by' => $request->user()->id,
            'published_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $duplicate->update(['current_version_id' => $version->id]);

        // Metadata only — never the copied content_template text itself.
        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template.duplicated',
            module: 'hr_documents',
            tenantId: $tenantId,
            auditableType: HrDocumentTemplate::class,
            auditableId: $duplicate->id,
            description: "HR document template '{$duplicate->title}' duplicated from '{$hrDocumentTemplate->title}'.",
            newValues: $duplicate->only(['title', 'slug', 'document_type', 'status']),
            metadata: ['source_template_id' => $hrDocumentTemplate->id, 'current_version_id' => $version->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new HrDocumentTemplateResource($duplicate->fresh()))->response()->setStatusCode(201);
    }

    /**
     * "{title} (Copy)", then "(Copy 2)", "(Copy 3)"... on collision — the
     * same auto-increment-on-collision idea HrDocumentTemplateVersionController
     * already uses for version_number, applied here to title/slug
     * uniqueness instead.
     *
     * @return array{0: string, 1: string}
     */
    protected function uniqueDuplicateTitleAndSlug(HrDocumentTemplate $source, string $tenantId): array
    {
        $attempt = 1;

        do {
            $suffix = $attempt === 1 ? ' (Copy)' : " (Copy {$attempt})";
            $title = $source->title.$suffix;
            $slug = Str::slug($title);
            $exists = HrDocumentTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('title', $title)->orWhere('slug', $slug))
                ->exists();
            $attempt++;
        } while ($exists);

        return [$title, $slug];
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
