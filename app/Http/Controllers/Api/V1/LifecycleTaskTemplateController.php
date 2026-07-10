<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LifecycleTaskTemplate\StoreLifecycleTaskTemplateRequest;
use App\Http\Requests\LifecycleTaskTemplate\UpdateLifecycleTaskTemplateRequest;
use App\Http\Resources\LifecycleTaskTemplateResource;
use App\Models\LifecycleTaskTemplate;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Checkpoint 42 — Onboarding & Offboarding Task Templates Foundation.
 * Same shape as DepartmentController/DocumentCategoryController: a
 * tenant-owned lookup catalog, soft-delete-only "archive", standard
 * two-layer tenant isolation (BelongsToTenant's global scope +
 * ensureBelongsToCurrentTenant() defense in depth).
 */
class LifecycleTaskTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = LifecycleTaskTemplate::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate();

        return LifecycleTaskTemplateResource::collection($templates);
    }

    public function store(StoreLifecycleTaskTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['sort_order'] ??= 0;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $template = LifecycleTaskTemplate::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task_template.created',
            module: 'lifecycle',
            tenantId: $template->tenant_id,
            auditableType: LifecycleTaskTemplate::class,
            auditableId: $template->id,
            description: "Lifecycle task template '{$template->title}' created ({$template->type->value}).",
            newValues: $template->only(['type', 'title', 'description', 'due_in_days', 'sort_order']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new LifecycleTaskTemplateResource($template))->response()->setStatusCode(201);
    }

    public function show(Request $request, LifecycleTaskTemplate $lifecycleTaskTemplate): LifecycleTaskTemplateResource
    {
        $this->ensureBelongsToCurrentTenant($lifecycleTaskTemplate);

        return new LifecycleTaskTemplateResource($lifecycleTaskTemplate);
    }

    public function update(UpdateLifecycleTaskTemplateRequest $request, LifecycleTaskTemplate $lifecycleTaskTemplate): LifecycleTaskTemplateResource
    {
        $this->ensureBelongsToCurrentTenant($lifecycleTaskTemplate);

        $originalValues = $lifecycleTaskTemplate->getOriginal();

        $lifecycleTaskTemplate->fill($request->validated());
        $lifecycleTaskTemplate->updated_by = $request->user()->id;
        $lifecycleTaskTemplate->save();

        $changes = $lifecycleTaskTemplate->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'lifecycle_task_template.updated',
                module: 'lifecycle',
                tenantId: $lifecycleTaskTemplate->tenant_id,
                auditableType: LifecycleTaskTemplate::class,
                auditableId: $lifecycleTaskTemplate->id,
                description: "Lifecycle task template '{$lifecycleTaskTemplate->title}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LifecycleTaskTemplateResource($lifecycleTaskTemplate);
    }

    /**
     * Soft delete only — an archived template simply stops being copied
     * into newly created processes (LifecycleTaskTemplateApplier only
     * ever queries non-trashed rows); it has zero effect on any
     * LifecycleTask already generated from it, since generated tasks
     * copy their fields at creation time and keep no live link back to
     * the template afterward (see docs/architecture.md).
     */
    public function destroy(Request $request, LifecycleTaskTemplate $lifecycleTaskTemplate): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($lifecycleTaskTemplate);

        $snapshot = $lifecycleTaskTemplate->only(['type', 'title']);

        $lifecycleTaskTemplate->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task_template.archived',
            module: 'lifecycle',
            tenantId: $lifecycleTaskTemplate->tenant_id,
            auditableType: LifecycleTaskTemplate::class,
            auditableId: $lifecycleTaskTemplate->id,
            description: "Lifecycle task template '{$lifecycleTaskTemplate->title}' archived.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Lifecycle task template archived.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(LifecycleTaskTemplate $template): void
    {
        abort_unless($template->tenant_id === app(Tenant::class)->id, 404);
    }
}
