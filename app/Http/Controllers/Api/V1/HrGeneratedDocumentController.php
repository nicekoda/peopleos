<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HrDocumentTemplateVersionStatus;
use App\Enums\HrGeneratedDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HrDocument\GenerateHrDocumentRequest;
use App\Http\Requests\HrDocument\RejectHrGeneratedDocumentRequest;
use App\Http\Requests\HrDocument\UpdateHrGeneratedDocumentRequest;
use App\Http\Resources\HrGeneratedDocumentResource;
use App\Models\Employee;
use App\Models\HrDocumentTemplate;
use App\Models\HrGeneratedDocument;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\HrDocuments\HrDocumentPdfRenderer;
use App\Services\HrDocuments\PlaceholderRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class HrGeneratedDocumentController extends Controller
{
    /**
     * Optional ?employee_id= filter — same "query-string filter on a
     * top-level tenant-scoped list" shape already used by
     * /lifecycle-processes?employeeId=. A filter value belonging to
     * another tenant is rejected the same way a route-bound model would
     * be: 404, not a silently-empty list, so a guessed cross-tenant
     * employee ID never distinguishes "exists elsewhere" from "no
     * documents".
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = HrGeneratedDocument::query()->with('employee')->orderByDesc('created_at');

        if ($request->filled('employee_id')) {
            $employee = Employee::query()->find($request->query('employee_id'));
            abort_unless($employee && $employee->tenant_id === app(Tenant::class)->id, 404);
            $query->where('employee_id', $employee->id);
        }

        return HrGeneratedDocumentResource::collection($query->paginate());
    }

    public function store(GenerateHrDocumentRequest $request): JsonResponse
    {
        $tenant = app(Tenant::class);
        $validated = $request->validated();

        /** @var Employee $employee */
        $employee = Employee::query()->with(['department', 'position', 'location'])->findOrFail($validated['employee_id']);
        $this->ensureEmployeeBelongsToCurrentTenant($employee);

        /** @var HrDocumentTemplate $template */
        $template = HrDocumentTemplate::query()->with('currentVersion')->findOrFail($validated['hr_document_template_id']);
        $this->ensureTemplateBelongsToCurrentTenant($template);

        // Defense in depth beyond GenerateHrDocumentRequest's
        // whereNotNull('current_version_id') check — guards the race
        // where a version is archived/unpublished between validation and
        // this line.
        $version = $template->currentVersion;
        abort_unless(
            $version && $version->status === HrDocumentTemplateVersionStatus::Published,
            422,
            'This template has no published version to generate from.',
        );

        $renderedContent = PlaceholderRenderer::render($version->content_template, $employee, $tenant);

        $document = HrGeneratedDocument::query()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'hr_document_template_id' => $template->id,
            'hr_document_template_version_id' => $version->id,
            'title' => $validated['title'] ?? $template->title,
            'document_type' => $template->document_type,
            // Checkpoint 37 — always draft, regardless of the generating
            // user's permissions (your approved rule: no auto-approve
            // shortcut). Submit + approve is always a separate, explicit
            // step from here.
            'status' => HrGeneratedDocumentStatus::Draft,
            'rendered_content' => $renderedContent,
            'generated_at' => now(),
            'generated_by' => $request->user()->id,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        // Metadata only — never the rendered letter content itself.
        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_generated_document.generated',
            module: 'hr_documents',
            tenantId: $tenant->id,
            auditableType: HrGeneratedDocument::class,
            auditableId: $document->id,
            description: "HR document '{$document->title}' generated for employee #{$employee->id}.",
            metadata: [
                'employee_id' => $employee->id,
                'hr_document_template_id' => $template->id,
                'document_type' => $document->document_type->value,
                'title' => $document->title,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new HrGeneratedDocumentResource($document->load('employee')))->response()->setStatusCode(201);
    }

    public function show(Request $request, HrGeneratedDocument $hrGeneratedDocument): HrGeneratedDocumentResource
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        return new HrGeneratedDocumentResource($hrGeneratedDocument->load('employee'));
    }

    /**
     * Title only — see UpdateHrGeneratedDocumentRequest. rendered_content
     * is never re-derived or accepted here; a title correction doesn't
     * re-run placeholder rendering.
     */
    public function update(UpdateHrGeneratedDocumentRequest $request, HrGeneratedDocument $hrGeneratedDocument): HrGeneratedDocumentResource
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        $originalValues = $hrGeneratedDocument->getOriginal();

        $hrGeneratedDocument->title = $request->validated('title');
        $hrGeneratedDocument->updated_by = $request->user()->id;
        $hrGeneratedDocument->save();

        $changes = $hrGeneratedDocument->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'hr_generated_document.updated',
                module: 'hr_documents',
                tenantId: $hrGeneratedDocument->tenant_id,
                auditableType: HrGeneratedDocument::class,
                auditableId: $hrGeneratedDocument->id,
                description: "HR document '{$hrGeneratedDocument->title}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new HrGeneratedDocumentResource($hrGeneratedDocument->load('employee'));
    }

    /**
     * Checkpoint 37 — handles both the original submission (draft ->
     * pending_approval, logged as `.submitted`) and a resubmission after
     * rejection (rejected -> pending_approval, logged as `.resubmitted`)
     * through the same endpoint, per the required audit-action list.
     * `HrGeneratedDocumentStatus::canTransitionTo()` is the single source
     * of truth for which transition is legal — no other status may
     * submit.
     */
    public function submit(Request $request, HrGeneratedDocument $hrGeneratedDocument): HrGeneratedDocumentResource
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        $fromStatus = $hrGeneratedDocument->status;
        abort_unless(
            $fromStatus->canTransitionTo(HrGeneratedDocumentStatus::PendingApproval),
            422,
            'This document cannot be submitted for approval from its current status.',
        );

        $hrGeneratedDocument->update([
            'status' => HrGeneratedDocumentStatus::PendingApproval,
            'submitted_at' => now(),
            'submitted_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: $fromStatus === HrGeneratedDocumentStatus::Rejected
                ? 'hr_generated_document.resubmitted'
                : 'hr_generated_document.submitted',
            module: 'hr_documents',
            tenantId: $hrGeneratedDocument->tenant_id,
            auditableType: HrGeneratedDocument::class,
            auditableId: $hrGeneratedDocument->id,
            description: "HR document '{$hrGeneratedDocument->title}' submitted for approval.",
            metadata: ['from_status' => $fromStatus->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new HrGeneratedDocumentResource($hrGeneratedDocument->load('employee'));
    }

    /**
     * pending_approval -> approved only. approved_at/approved_by are set
     * here, server-side, never accepted from request input.
     */
    public function approve(Request $request, HrGeneratedDocument $hrGeneratedDocument): HrGeneratedDocumentResource
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        abort_unless(
            $hrGeneratedDocument->status->canTransitionTo(HrGeneratedDocumentStatus::Approved),
            422,
            'This document cannot be approved from its current status.',
        );

        $hrGeneratedDocument->update([
            'status' => HrGeneratedDocumentStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_generated_document.approved',
            module: 'hr_documents',
            tenantId: $hrGeneratedDocument->tenant_id,
            auditableType: HrGeneratedDocument::class,
            auditableId: $hrGeneratedDocument->id,
            description: "HR document '{$hrGeneratedDocument->title}' approved.",
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new HrGeneratedDocumentResource($hrGeneratedDocument->load('employee'));
    }

    /**
     * pending_approval -> rejected only. rejected_at/rejected_by are set
     * here, server-side; rejection_reason comes from
     * RejectHrGeneratedDocumentRequest. The reason is stored on the
     * model (needed for the UI/rejected user to see why) but deliberately
     * never included in audit metadata — same "free-text content never
     * passed to the logger" discipline already established for lifecycle
     * task descriptions and this module's own rendered_content.
     */
    public function reject(RejectHrGeneratedDocumentRequest $request, HrGeneratedDocument $hrGeneratedDocument): HrGeneratedDocumentResource
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        abort_unless(
            $hrGeneratedDocument->status->canTransitionTo(HrGeneratedDocumentStatus::Rejected),
            422,
            'This document cannot be rejected from its current status.',
        );

        $hrGeneratedDocument->update([
            'status' => HrGeneratedDocumentStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $request->validated('rejection_reason'),
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_generated_document.rejected',
            module: 'hr_documents',
            tenantId: $hrGeneratedDocument->tenant_id,
            auditableType: HrGeneratedDocument::class,
            auditableId: $hrGeneratedDocument->id,
            description: "HR document '{$hrGeneratedDocument->title}' rejected.",
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new HrGeneratedDocumentResource($hrGeneratedDocument->load('employee'));
    }

    /**
     * Checkpoint 35 — Option B (approved): renders the PDF on demand from
     * the already-stored rendered_content and streams it straight back;
     * nothing is ever written to any disk, so there is no storage path to
     * leak and no file lifecycle to manage. Gated by the same
     * hr_generated_documents.view permission as GET .../{id} — downloading
     * a PDF of a document you can already view in JSON is not a new
     * capability, so no new permission was introduced for it.
     */
    public function downloadPdf(Request $request, HrGeneratedDocument $hrGeneratedDocument): Response
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        $tenant = app(Tenant::class);
        $pdfBytes = HrDocumentPdfRenderer::render($hrGeneratedDocument->load('employee'), $tenant);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_generated_document.pdf_downloaded',
            module: 'hr_documents',
            tenantId: $hrGeneratedDocument->tenant_id,
            auditableType: HrGeneratedDocument::class,
            auditableId: $hrGeneratedDocument->id,
            description: "HR document '{$hrGeneratedDocument->title}' downloaded as PDF.",
            metadata: [
                'employee_id' => $hrGeneratedDocument->employee_id,
                'document_type' => $hrGeneratedDocument->document_type->value,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $filename = Str::slug($hrGeneratedDocument->title).'.pdf';

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Soft delete ("archive") — same shape as
     * HrDocumentTemplateController::destroy()/DocumentCategoryController::destroy(),
     * plus (Checkpoint 37) actually transitions `status` to `archived`
     * rather than leaving it at whatever it was, now that `archived` is a
     * real, reachable state in the approval flow. `archived` itself is
     * terminal — an already-archived row can't be found here at all
     * (soft-deleted, excluded from queries), so no further guard is needed.
     */
    public function destroy(Request $request, HrGeneratedDocument $hrGeneratedDocument): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($hrGeneratedDocument);

        abort_unless(
            $hrGeneratedDocument->status->canTransitionTo(HrGeneratedDocumentStatus::Archived),
            422,
            'This document cannot be archived from its current status.',
        );

        $snapshot = $hrGeneratedDocument->only(['title', 'document_type', 'status', 'employee_id']);

        $hrGeneratedDocument->status = HrGeneratedDocumentStatus::Archived;
        $hrGeneratedDocument->updated_by = $request->user()->id;
        $hrGeneratedDocument->save();
        $hrGeneratedDocument->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'hr_generated_document.archived',
            module: 'hr_documents',
            tenantId: $hrGeneratedDocument->tenant_id,
            auditableType: HrGeneratedDocument::class,
            auditableId: $hrGeneratedDocument->id,
            description: "HR document '{$hrGeneratedDocument->title}' archived.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'HR document archived.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403: don't
     * reveal that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(HrGeneratedDocument $hrGeneratedDocument): void
    {
        abort_unless($hrGeneratedDocument->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureEmployeeBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureTemplateBelongsToCurrentTenant(HrDocumentTemplate $template): void
    {
        abort_unless($template->tenant_id === app(Tenant::class)->id, 404);
    }
}
