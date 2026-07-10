<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStage;
use App\Enums\ApplicationStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LifecycleProcessStatus;
use App\Enums\LifecycleProcessType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\ConvertApplicationToEmployeeRequest;
use App\Http\Requests\Recruitment\MarkApplicationReadyForConversionRequest;
use App\Http\Requests\Recruitment\StoreApplicationNoteRequest;
use App\Http\Requests\Recruitment\StoreJobApplicationRequest;
use App\Http\Requests\Recruitment\UpdateApplicationStageRequest;
use App\Http\Requests\Recruitment\UpdateJobApplicationRequest;
use App\Http\Resources\JobApplicationResource;
use App\Http\Resources\RecruitmentApplicationNoteResource;
use App\Models\Employee;
use App\Models\LifecycleProcess;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentApplicationNote;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\LifecycleTaskTemplateApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class JobApplicationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $applications = RecruitmentApplication::query()
            ->with(['job', 'applicant', 'convertedEmployee', 'onboardingProcess'])
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

        $jobApplication->load(['job', 'applicant', 'notes.createdBy', 'convertedEmployee', 'onboardingProcess']);

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
     * Checkpoint 40 — creates a real Employee row from an eligible
     * application (stage: hired, ready_for_conversion: true, not already
     * converted — all re-checked here, not just in the FormRequest, as
     * defense in depth). Runs in a transaction: employee creation and
     * the application's converted_* fields succeed or fail together — a
     * uniqueness failure never leaves a half-converted application.
     * manager_employee_id is deliberately never set here — assigning a
     * manager stays the exclusive job of PATCH /employees/{id}/manager,
     * same as every other employee-creation path in this app. No user
     * account, role assignment, or onboarding process is started
     * automatically (see docs/architecture.md for the documented future
     * flow).
     */
    public function convertToEmployee(ConvertApplicationToEmployeeRequest $request, RecruitmentApplication $jobApplication): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        abort_unless($jobApplication->converted_employee_id === null, 422, 'This application has already been converted to an employee.');
        abort_unless(
            $jobApplication->stage === ApplicationStage::Hired && $jobApplication->ready_for_conversion,
            422,
            'This application must be at the hired stage and marked ready for conversion before it can be converted.',
        );

        $validated = $request->validated();
        $tenantId = $jobApplication->tenant_id;
        $applicant = $jobApplication->applicant;

        $employee = DB::transaction(function () use ($validated, $tenantId, $applicant, $jobApplication, $request) {
            $employee = Employee::query()->create([
                'tenant_id' => $tenantId,
                'employee_number' => $validated['employee_number'],
                'first_name' => $applicant->first_name,
                'last_name' => $applicant->last_name,
                'work_email' => $validated['work_email'] ?? null,
                'status' => $validated['status'] ?? EmployeeStatus::Draft->value,
                'employment_type' => $validated['employment_type'],
                'department_id' => $validated['department_id'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
                'position_id' => $validated['position_id'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $jobApplication->converted_employee_id = $employee->id;
            $jobApplication->converted_at = now();
            $jobApplication->converted_by = $request->user()->id;
            $jobApplication->updated_by = $request->user()->id;
            $jobApplication->save();

            return $employee;
        });

        // Never the applicant's cover letter/notes — only IDs and the
        // safe, already-audited employee fields.
        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application.converted_to_employee',
            module: 'recruitment',
            tenantId: $tenantId,
            auditableType: RecruitmentApplication::class,
            auditableId: $jobApplication->id,
            description: "Application from '{$applicant->first_name} {$applicant->last_name}' converted to employee #{$employee->employee_number}.",
            newValues: ['converted_employee_id' => $employee->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee.created_from_recruitment',
            module: 'employees',
            tenantId: $tenantId,
            auditableType: Employee::class,
            auditableId: $employee->id,
            description: "Employee '{$employee->fullName()}' (#{$employee->employee_number}) created from recruitment application.",
            newValues: $employee->only(['employee_number', 'first_name', 'last_name', 'work_email', 'status', 'employment_type', 'department_id', 'location_id', 'position_id', 'start_date']),
            metadata: ['source_application_id' => $jobApplication->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new JobApplicationResource($jobApplication->fresh(['job', 'applicant', 'convertedEmployee', 'onboardingProcess'])))->response()->setStatusCode(200);
    }

    /**
     * Checkpoint 41 — Recruitment-to-Onboarding Handoff Foundation.
     * Takes no request body at all: employee_id/type/status are always
     * server-derived, never accepted from input. Gated by lifecycle.create
     * (not a new recruitment-specific permission, per your explicit
     * approved choice — starting onboarding is a lifecycle action, not
     * just a recruitment one). No user account, role assignment, or
     * notifications (explicitly out of scope). Runs in a transaction:
     * the process, its template-derived tasks (Checkpoint 42 — see
     * LifecycleTaskTemplateApplier), and the application's
     * onboarding_process_id link all succeed or fail together.
     */
    public function startOnboarding(Request $request, RecruitmentApplication $jobApplication): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($jobApplication);

        abort_unless($jobApplication->converted_employee_id !== null, 422, 'This application has not been converted to an employee yet.');
        abort_unless($jobApplication->onboarding_process_id === null, 422, 'Onboarding has already been started for this application.');

        $employee = $jobApplication->convertedEmployee;
        abort_unless($employee !== null && $employee->tenant_id === $jobApplication->tenant_id, 404);

        $hasActiveOnboarding = LifecycleProcess::query()
            ->where('employee_id', $employee->id)
            ->where('type', LifecycleProcessType::Onboarding->value)
            ->whereNotIn('status', [LifecycleProcessStatus::Completed->value, LifecycleProcessStatus::Cancelled->value])
            ->exists();
        abort_if($hasActiveOnboarding, 422, 'This employee already has an active onboarding process.');

        $process = DB::transaction(function () use ($jobApplication, $employee, $request) {
            $process = LifecycleProcess::query()->create([
                'tenant_id' => $jobApplication->tenant_id,
                'employee_id' => $employee->id,
                'type' => LifecycleProcessType::Onboarding->value,
                'status' => LifecycleProcessStatus::Draft->value,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            LifecycleTaskTemplateApplier::applyToProcess($process, $request->user()->id);

            $jobApplication->onboarding_process_id = $process->id;
            $jobApplication->updated_by = $request->user()->id;
            $jobApplication->save();

            return $process;
        });

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_application.onboarding_started',
            module: 'recruitment',
            tenantId: $jobApplication->tenant_id,
            auditableType: RecruitmentApplication::class,
            auditableId: $jobApplication->id,
            description: "Onboarding started for employee '{$employee->fullName()}' (#{$employee->employee_number}) from recruitment application.",
            newValues: ['onboarding_process_id' => $process->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee_lifecycle_process.created_from_recruitment',
            module: 'lifecycle',
            tenantId: $jobApplication->tenant_id,
            auditableType: LifecycleProcess::class,
            auditableId: $process->id,
            description: "Onboarding process created for employee '{$employee->fullName()}' from recruitment application.",
            metadata: ['source_application_id' => $jobApplication->id, 'employee_id' => $employee->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $taskCount = $process->tasks()->count();
        if ($taskCount > 0) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'lifecycle_process.tasks_applied_from_templates',
                module: 'lifecycle',
                tenantId: $jobApplication->tenant_id,
                auditableType: LifecycleProcess::class,
                auditableId: $process->id,
                description: "{$taskCount} task(s) applied from templates (onboarding).",
                metadata: ['task_count' => $taskCount, 'type' => 'onboarding'],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return (new JobApplicationResource($jobApplication->fresh(['job', 'applicant', 'convertedEmployee', 'onboardingProcess'])))->response()->setStatusCode(200);
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
