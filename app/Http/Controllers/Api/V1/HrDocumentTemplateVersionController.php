<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HrDocumentTemplateVersionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HrDocument\StoreHrDocumentTemplateVersionRequest;
use App\Http\Requests\HrDocument\UpdateHrDocumentTemplateVersionRequest;
use App\Http\Resources\HrDocumentTemplateVersionResource;
use App\Models\HrDocumentTemplate;
use App\Models\HrDocumentTemplateVersion;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HrDocumentTemplateVersionController extends Controller
{
    /**
     * Read-only, scoped through $hrDocumentTemplate->versions() — never a
     * free query filtered by a request-supplied template ID — same
     * pattern as PolicyController::versions().
     */
    public function index(Request $request, HrDocumentTemplate $hrDocumentTemplate): AnonymousResourceCollection
    {
        $this->ensureTemplateBelongsToCurrentTenant($hrDocumentTemplate);

        $versions = $hrDocumentTemplate->versions()->orderByDesc('version_number')->paginate();

        return HrDocumentTemplateVersionResource::collection($versions);
    }

    /**
     * Always creates a draft — version_number is auto-computed the same
     * way PolicyController::storeVersion() computes it, but withTrashed()
     * so a previously soft-deleted draft's number is never reused (the
     * unique(tenant_id, hr_document_template_id, version_number)
     * constraint doesn't know about soft deletes).
     */
    public function store(StoreHrDocumentTemplateVersionRequest $request, HrDocumentTemplate $hrDocumentTemplate): JsonResponse
    {
        $this->ensureTemplateBelongsToCurrentTenant($hrDocumentTemplate);

        $nextVersionNumber = (int) $hrDocumentTemplate->versions()->withTrashed()->max('version_number') + 1;

        $version = HrDocumentTemplateVersion::query()->create([
            'tenant_id' => $hrDocumentTemplate->tenant_id,
            'hr_document_template_id' => $hrDocumentTemplate->id,
            'version_number' => $nextVersionNumber,
            'content_template' => $request->validated('content_template'),
            'status' => HrDocumentTemplateVersionStatus::Draft,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template_version.created',
            module: 'hr_documents',
            tenantId: $hrDocumentTemplate->tenant_id,
            auditableType: HrDocumentTemplateVersion::class,
            auditableId: $version->id,
            description: "Draft version {$version->version_number} created for HR document template '{$hrDocumentTemplate->title}'.",
            metadata: ['hr_document_template_id' => $hrDocumentTemplate->id, 'version_number' => $version->version_number],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new HrDocumentTemplateVersionResource($version))->response()->setStatusCode(201);
    }

    public function show(Request $request, HrDocumentTemplateVersion $hrDocumentTemplateVersion): HrDocumentTemplateVersionResource
    {
        $this->ensureVersionBelongsToCurrentTenant($hrDocumentTemplateVersion);

        return new HrDocumentTemplateVersionResource($hrDocumentTemplateVersion);
    }

    /**
     * content_template only, and only while the version is still draft —
     * see UpdateHrDocumentTemplateVersionRequest::withValidator(). A
     * published or archived version is immutable history from here on.
     */
    public function update(UpdateHrDocumentTemplateVersionRequest $request, HrDocumentTemplateVersion $hrDocumentTemplateVersion): HrDocumentTemplateVersionResource
    {
        $this->ensureVersionBelongsToCurrentTenant($hrDocumentTemplateVersion);

        $hrDocumentTemplateVersion->content_template = $request->validated('content_template');
        $hrDocumentTemplateVersion->updated_by = $request->user()->id;
        $hrDocumentTemplateVersion->save();

        if ($hrDocumentTemplateVersion->wasChanged('content_template')) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'hr_document_template_version.updated',
                module: 'hr_documents',
                tenantId: $hrDocumentTemplateVersion->tenant_id,
                auditableType: HrDocumentTemplateVersion::class,
                auditableId: $hrDocumentTemplateVersion->id,
                description: "Draft version {$hrDocumentTemplateVersion->version_number} updated.",
                metadata: [
                    'hr_document_template_id' => $hrDocumentTemplateVersion->hr_document_template_id,
                    'version_number' => $hrDocumentTemplateVersion->version_number,
                ],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new HrDocumentTemplateVersionResource($hrDocumentTemplateVersion);
    }

    /**
     * Promotes this version to published, demotes whichever version was
     * previously published for the same template to archived (never
     * deleted — old wording stays available for history), and points the
     * template's current_version_id at this one. published_at/published_by
     * are set here, server-side only — never accepted from request input.
     * Same "only one published version at a time" invariant
     * PolicyController::publish() already enforces; also allows
     * "republishing" an older archived version as a deliberate rollback,
     * the same latitude Policy's publish already has (no status guard on
     * the target version itself).
     */
    public function publish(Request $request, HrDocumentTemplateVersion $hrDocumentTemplateVersion): HrDocumentTemplateVersionResource
    {
        $this->ensureVersionBelongsToCurrentTenant($hrDocumentTemplateVersion);

        $template = $hrDocumentTemplateVersion->template;

        HrDocumentTemplateVersion::query()
            ->where('hr_document_template_id', $template->id)
            ->where('status', HrDocumentTemplateVersionStatus::Published)
            ->where('id', '!=', $hrDocumentTemplateVersion->id)
            ->update(['status' => HrDocumentTemplateVersionStatus::Archived]);

        $hrDocumentTemplateVersion->update([
            'status' => HrDocumentTemplateVersionStatus::Published,
            'published_by' => $request->user()->id,
            'published_at' => now(),
        ]);

        $template->update(['current_version_id' => $hrDocumentTemplateVersion->id]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template_version.published',
            module: 'hr_documents',
            tenantId: $hrDocumentTemplateVersion->tenant_id,
            auditableType: HrDocumentTemplateVersion::class,
            auditableId: $hrDocumentTemplateVersion->id,
            description: "Version {$hrDocumentTemplateVersion->version_number} published for HR document template '{$template->title}'.",
            metadata: ['hr_document_template_id' => $template->id, 'version_number' => $hrDocumentTemplateVersion->version_number],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new HrDocumentTemplateVersionResource($hrDocumentTemplateVersion->fresh());
    }

    /**
     * Draft only — a published or archived version is never deletable
     * (Checkpoint 36 approved rule: "do not delete old versions" applies
     * to anything that was ever live). Soft delete only, same shape as
     * every other archive-style destroy() in this app.
     */
    public function destroy(Request $request, HrDocumentTemplateVersion $hrDocumentTemplateVersion): JsonResponse
    {
        $this->ensureVersionBelongsToCurrentTenant($hrDocumentTemplateVersion);

        abort_unless(
            $hrDocumentTemplateVersion->status === HrDocumentTemplateVersionStatus::Draft,
            422,
            'Only a draft version can be deleted.',
        );

        $snapshot = ['version_number' => $hrDocumentTemplateVersion->version_number, 'status' => $hrDocumentTemplateVersion->status->value];

        $hrDocumentTemplateVersion->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_document_template_version.archived',
            module: 'hr_documents',
            tenantId: $hrDocumentTemplateVersion->tenant_id,
            auditableType: HrDocumentTemplateVersion::class,
            auditableId: $hrDocumentTemplateVersion->id,
            description: "Draft version {$hrDocumentTemplateVersion->version_number} deleted.",
            oldValues: $snapshot,
            metadata: ['hr_document_template_id' => $hrDocumentTemplateVersion->hr_document_template_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Draft version deleted.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403: don't
     * reveal that a record exists in another tenant.
     */
    protected function ensureTemplateBelongsToCurrentTenant(HrDocumentTemplate $hrDocumentTemplate): void
    {
        abort_unless($hrDocumentTemplate->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureVersionBelongsToCurrentTenant(HrDocumentTemplateVersion $hrDocumentTemplateVersion): void
    {
        abort_unless($hrDocumentTemplateVersion->tenant_id === app(Tenant::class)->id, 404);
    }
}
