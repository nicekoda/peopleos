<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStage;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\MarkApplicationReadyForConversionRequest;
use App\Http\Requests\Recruitment\StoreApplicationNoteRequest;
use App\Http\Requests\Recruitment\StoreJobApplicationRequest;
use App\Http\Requests\Recruitment\UpdateApplicationStageRequest;
use App\Http\Requests\Recruitment\UpdateJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use App\Http\Resources\RecruitmentApplicationNoteResource;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationNote;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JobApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $applications = RecruitmentApplication::query()
            ->with(['job', 'applicant'])
            ->orderByDesc('created_at')
            ->paginate();

        return JobApplicationResource::collection($applications);
    }

    /**
     * One-step create: the applicant (identity) and the application (this
     * person's application to this job) are created together in a single
     * request, same "single-step" shape as
     * HrDocumentTemplateController::store() creating a template and its
     * first version together. No dedupe against an existing applicant
     * with the same email — every submission creates a fresh
     * RecruitmentApplicant row (documented limitation, see docs/security.md).
     */
    public function store(StoreJobApplicationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tenantId = app(Tenant::class)->id;

        $applicant = RecruitmentApplicant::query()->create([
            'tenant_id' => $tenantId,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'source' => $validated['source'] ?? null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        $application = RecruitmentApplication::query()->create([
            'tenant_id' => $tenantId,
            'recruitment_job_id' => $validated['recruitment_job_id'],
            'recruitment_applicant_id' => $applicant->id,
            'stage' => ApplicationStage::Applied->value,
            'status' => ApplicationStatus::Active->value,
            'cover_letter' => $validated['cover_letter'] ?? null,
            'ready_for_conversion' => false,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        // Never the cover letter itself — only identifying/pipeline fields.
        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application.created',
            module: 'recruitment',
            tenantId: $tenantId,
            auditableType: RecruitmentApplication::class,
            auditableId: $application->id,
            description: "Application from '{$applicant->first_name} {$applicant->last_name}' created for job opening {$application->recruitment_job_id}.",
            newValues: $application->only(['recruitment_job_id', 'stage', 'status']),
            metadata: ['applicant_id' => $applicant->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new JobApplicationResource($application->load(['job', 'applicant'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, RecruitmentApplication $jobApplication): JobApplicationResource
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        $jobApplication->load(['job', 'applicant', 'notes.createdBy']);

        return new JobApplicationResource($jobApplication);
    }

    public function update(UpdateJobApplicationRequest $request, RecruitmentApplication $jobApplication): JobApplicationResource
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        $validated = $request->validated();
        $applicantFields = array_intersect_key($validated, array_flip(['first_name', 'last_name', 'email', 'phone', 'source']));
        $applicationFields = array_intersect_key($validated, array_flip(['cover_letter']));

        if ($applicantFields !== []) {
            $applicant = $jobApplication->applicant;
            $originalApplicantValues = $applicant->getOriginal();
            $applicant->fill($applicantFields);
            $applicant->updated_by = $request->user()->id;
            $applicant->save();

            $applicantChanges = $applicant->getChanges();
            unset($applicantChanges['updated_at'], $applicantChanges['updated_by']);

            if ($applicantChanges !== []) {
                AuditLogger::logFor(
                    actor: $request->user(),
                    action: 'job_application.updated',
                    module: 'recruitment',
                    tenantId: $jobApplication->tenant_id,
                    auditableType: RecruitmentApplication::class,
                    auditableId: $jobApplication->id,
                    description: 'Application applicant details updated.',
                    oldValues: array_intersect_key($originalApplicantValues, $applicantChanges),
                    newValues: $applicantChanges,
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }
        }

        if ($applicationFields !== []) {
            $jobApplication->fill($applicationFields);
            $jobApplication->updated_by = $request->user()->id;
            $jobApplication->save();

            // cover_letter changed, but its text is never written to the
            // audit log — only the fact that it changed.
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'job_application.updated',
                module: 'recruitment',
                tenantId: $jobApplication->tenant_id,
                auditableType: RecruitmentApplication::class,
                auditableId: $jobApplication->id,
                description: 'Application cover letter updated.',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new JobApplicationResource($jobApplication->fresh(['job', 'applicant']));
    }

    /**
     * Soft-archive, never a hard delete — mirrors JobOpeningController.
     */
    public function destroy(Request $request, RecruitmentApplication $jobApplication): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        $jobApplication->status = ApplicationStatus::Archived;
        $jobApplication->updated_by = $request->user()->id;
        $jobApplication->save();
        $jobApplication->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application.archived',
            module: 'recruitment',
            tenantId: $jobApplication->tenant_id,
            auditableType: RecruitmentApplication::class,
            auditableId: $jobApplication->id,
            description: 'Application archived.',
            newValues: ['status' => $jobApplication->status->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Application archived.']);
    }

    public function updateStage(UpdateApplicationStageRequest $request, RecruitmentApplication $jobApplication): JobApplicationResource
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        $fromStage = $jobApplication->stage;
        $jobApplication->stage = $request->validated('stage');
        $jobApplication->updated_by = $request->user()->id;
        $jobApplication->save();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application.stage_changed',
            module: 'recruitment',
            tenantId: $jobApplication->tenant_id,
            auditableType: RecruitmentApplication::class,
            auditableId: $jobApplication->id,
            description: "Application stage changed from {$fromStage->value} to {$jobApplication->stage->value}.",
            oldValues: ['stage' => $fromStage->value],
            newValues: ['stage' => $jobApplication->stage->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new JobApplicationResource($jobApplication->fresh(['job', 'applicant']));
    }

    /**
     * A milestone flag only — no employee is ever created here (see
     * docs/architecture.md for the documented future conversion flow).
     */
    public function markReadyForConversion(MarkApplicationReadyForConversionRequest $request, RecruitmentApplication $jobApplication): JobApplicationResource
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        $jobApplication->ready_for_conversion = $request->boolean('ready_for_conversion');
        $jobApplication->updated_by = $request->user()->id;
        $jobApplication->save();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application.marked_ready_for_conversion',
            module: 'recruitment',
            tenantId: $jobApplication->tenant_id,
            auditableType: RecruitmentApplication::class,
            auditableId: $jobApplication->id,
            description: 'Application ready-for-conversion flag changed.',
            newValues: ['ready_for_conversion' => $jobApplication->ready_for_conversion],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new JobApplicationResource($jobApplication->fresh(['job', 'applicant']));
    }

    /**
     * Internal-only — visibility is always 'internal', never accepted
     * from request input. Never logs the note's own text, only that a
     * note was added (see docs/security.md).
     */
    public function storeNote(StoreApplicationNoteRequest $request, RecruitmentApplication $jobApplication): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        $note = RecruitmentApplicationNote::query()->create([
            'tenant_id' => $jobApplication->tenant_id,
            'recruitment_application_id' => $jobApplication->id,
            'note' => $request->validated('note'),
            'visibility' => 'internal',
            'created_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application_note.created',
            module: 'recruitment',
            tenantId: $jobApplication->tenant_id,
            auditableType: RecruitmentApplicationNote::class,
            auditableId: $note->id,
            description: 'Internal note added to application.',
            metadata: ['recruitment_application_id' => $jobApplication->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new RecruitmentApplicationNoteResource($note))->response()->setStatusCode(201);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(RecruitmentApplication $application): void
    {
        abort_unless($application->tenant_id === app(Tenant::class)->id, 404);
    }
}
