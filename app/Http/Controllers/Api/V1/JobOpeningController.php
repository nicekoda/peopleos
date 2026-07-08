<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RecruitmentJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobOpeningRequest;
use App\Http\Requests\Recruitment\UpdateJobOpeningRequest;
use App\Http\Resources\JobOpeningResource;
use App\Models\RecruitmentJob;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JobOpeningController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $jobs = RecruitmentJob::query()
            ->with(['department', 'position', 'location'])
            ->orderByDesc('created_at')
            ->paginate();

        return JobOpeningResource::collection($jobs);
    }

    public function store(StoreJobOpeningRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['tenant_id'] = app(Tenant::class)->id;
        // Model::create() doesn't backfill DB column defaults into the
        // in-memory instance — explicit defaulting here, same fix used
        // by LifecycleProcessController.
        $validated['status'] = RecruitmentJobStatus::Draft->value;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $job = RecruitmentJob::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_opening.created',
            module: 'recruitment',
            tenantId: $job->tenant_id,
            auditableType: RecruitmentJob::class,
            auditableId: $job->id,
            description: "Job opening '{$job->title}' created.",
            newValues: $job->only(['title', 'department_id', 'position_id', 'location_id', 'employment_type', 'status']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new JobOpeningResource($job))->response()->setStatusCode(201);
    }

    public function show(Request $request, RecruitmentJob $jobOpening): JobOpeningResource
    {
        $this->ensureBelongsToCurrentTenant($jobOpening);

        $jobOpening->load(['department', 'position', 'location']);

        return new JobOpeningResource($jobOpening);
    }

    public function update(UpdateJobOpeningRequest $request, RecruitmentJob $jobOpening): JobOpeningResource
    {
        $this->ensureBelongsToCurrentTenant($jobOpening);

        $originalValues = $jobOpening->getOriginal();
        $validated = $request->validated();

        $newStatus = isset($validated['status']) ? RecruitmentJobStatus::from($validated['status']) : null;

        if ($newStatus === RecruitmentJobStatus::Open && $jobOpening->opened_at === null) {
            $validated['opened_at'] = now();
        }

        if (in_array($newStatus, [RecruitmentJobStatus::Closed, RecruitmentJobStatus::Cancelled], true)) {
            $validated['closed_at'] = now();
        }

        $jobOpening->fill($validated);
        $jobOpening->updated_by = $request->user()->id;
        $jobOpening->save();

        $changes = $jobOpening->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'job_opening.updated',
                module: 'recruitment',
                tenantId: $jobOpening->tenant_id,
                auditableType: RecruitmentJob::class,
                auditableId: $jobOpening->id,
                description: "Job opening '{$jobOpening->title}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new JobOpeningResource($jobOpening);
    }

    /**
     * Soft-archive, never a hard delete — mirrors LifecycleProcessController.
     * A non-terminal opening is cancelled first; an already-terminal
     * (closed/cancelled) opening is just hidden from the active list.
     */
    public function destroy(Request $request, RecruitmentJob $jobOpening): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($jobOpening);

        if (! $jobOpening->status->isTerminal()) {
            $jobOpening->status = RecruitmentJobStatus::Cancelled;
            $jobOpening->closed_at = now();
        }

        $jobOpening->updated_by = $request->user()->id;
        $jobOpening->save();
        $jobOpening->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'job_opening.archived',
            module: 'recruitment',
            tenantId: $jobOpening->tenant_id,
            auditableType: RecruitmentJob::class,
            auditableId: $jobOpening->id,
            description: "Job opening '{$jobOpening->title}' archived.",
            newValues: ['status' => $jobOpening->status->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Job opening archived.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(RecruitmentJob $job): void
    {
        abort_unless($job->tenant_id === app(Tenant::class)->id, 404);
    }
}
