<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LifecycleProcessStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lifecycle\StoreLifecycleProcessRequest;
use App\Http\Requests\Lifecycle\UpdateLifecycleProcessRequest;
use App\Http\Resources\LifecycleProcessResource;
use App\Models\LifecycleProcess;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\LifecycleTaskTemplateApplier;
use App\Services\LifecycleVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LifecycleProcessController extends Controller
{
    /**
     * Unrestricted callers (Tenant Admin/HR Manager/HR Officer/Auditor —
     * see LifecycleVisibilityService::hasUnrestrictedAccess()) see every
     * process in the tenant. Everyone else (Line Manager/Employee — the
     * identical lifecycle.view + lifecycle.complete_task permission set)
     * sees only processes for their own direct reports and/or containing
     * a task assigned to them.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $visibility = app(LifecycleVisibilityService::class);

        $query = LifecycleProcess::query()->with('employee')->orderByDesc('created_at');

        if (! $visibility->hasUnrestrictedAccess($user)) {
            $visibility->scopeProcessesQuery($user, $query);
        }

        return LifecycleProcessResource::collection($query->paginate());
    }

    /**
     * Checkpoint 42 — after creating the process itself, applies every
     * matching (same tenant + type) LifecycleTaskTemplate via
     * LifecycleTaskTemplateApplier, so a process no longer starts
     * completely bare when templates exist for its type. Wrapped in a
     * transaction (new this checkpoint — store() had none before) so
     * the process and its template-derived tasks are created together
     * or not at all.
     */
    public function store(StoreLifecycleProcessRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['tenant_id'] = app(Tenant::class)->id;
        // Model::create() doesn't backfill DB column defaults into the
        // in-memory instance — explicit defaulting here, same fix used
        // by LeaveRequestController/DocumentCategoryController.
        $validated['status'] = LifecycleProcessStatus::Draft->value;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $process = DB::transaction(function () use ($validated, $request) {
            $process = LifecycleProcess::query()->create($validated);

            LifecycleTaskTemplateApplier::applyToProcess($process, $request->user()->id);

            return $process;
        });

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_process.created',
            module: 'lifecycle',
            tenantId: $process->tenant_id,
            auditableType: LifecycleProcess::class,
            auditableId: $process->id,
            description: "Lifecycle process created ({$process->type->value}).",
            newValues: $process->only(['employee_id', 'type', 'status', 'started_at', 'due_date']),
            metadata: ['employee_id' => $process->employee_id, 'type' => $process->type->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $taskCount = $process->tasks()->count();
        if ($taskCount > 0) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'lifecycle_process.tasks_applied_from_templates',
                module: 'lifecycle',
                tenantId: $process->tenant_id,
                auditableType: LifecycleProcess::class,
                auditableId: $process->id,
                description: "{$taskCount} task(s) applied from templates ({$process->type->value}).",
                metadata: ['task_count' => $taskCount, 'type' => $process->type->value],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return (new LifecycleProcessResource($process->load('tasks')))->response()->setStatusCode(201);
    }

    public function show(Request $request, LifecycleProcess $lifecycleProcess): LifecycleProcessResource
    {
        $this->ensureBelongsToCurrentTenant($lifecycleProcess);
        $this->ensureCanAccess($request, $lifecycleProcess);

        $lifecycleProcess->load(['employee', 'tasks.assignedToUser']);

        return new LifecycleProcessResource($lifecycleProcess);
    }

    /**
     * Only Tenant Admin/HR Manager/HR Officer hold lifecycle.update — all
     * three qualify as "unrestricted" under LifecycleVisibilityService
     * by definition (holding any write permission on this resource *is*
     * what unrestricted means), so no separate object-level visibility
     * check is needed here beyond tenant membership.
     */
    public function update(UpdateLifecycleProcessRequest $request, LifecycleProcess $lifecycleProcess): LifecycleProcessResource
    {
        $this->ensureBelongsToCurrentTenant($lifecycleProcess);

        $originalValues = $lifecycleProcess->getOriginal();
        $validated = $request->validated();

        $newStatus = isset($validated['status']) ? LifecycleProcessStatus::from($validated['status']) : null;

        if ($newStatus === LifecycleProcessStatus::InProgress && $lifecycleProcess->started_at === null && ! isset($validated['started_at'])) {
            $validated['started_at'] = now();
        }

        if ($newStatus === LifecycleProcessStatus::Completed) {
            $validated['completed_at'] = now();
        }

        $lifecycleProcess->fill($validated);
        $lifecycleProcess->updated_by = $request->user()->id;
        $lifecycleProcess->save();

        $changes = $lifecycleProcess->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            $action = match ($newStatus) {
                LifecycleProcessStatus::Completed => 'lifecycle_process.completed',
                LifecycleProcessStatus::Cancelled => 'lifecycle_process.cancelled',
                default => 'lifecycle_process.updated',
            };

            AuditLogger::logFor(
                actor: $request->user(),
                action: $action,
                module: 'lifecycle',
                tenantId: $lifecycleProcess->tenant_id,
                auditableType: LifecycleProcess::class,
                auditableId: $lifecycleProcess->id,
                description: 'Lifecycle process updated.',
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                metadata: ['employee_id' => $lifecycleProcess->employee_id, 'type' => $lifecycleProcess->type->value],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LifecycleProcessResource($lifecycleProcess);
    }

    /**
     * Soft-archive, never a hard delete (Refinement 10). An in-progress/
     * draft process is transitioned to cancelled first; an already-
     * terminal (completed/cancelled) process is just hidden from the
     * active list, its status untouched — "cancelling" a completed
     * process would be a false statement.
     */
    public function destroy(Request $request, LifecycleProcess $lifecycleProcess): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($lifecycleProcess);

        if (! $lifecycleProcess->status->isTerminal()) {
            $lifecycleProcess->status = LifecycleProcessStatus::Cancelled;
        }

        $lifecycleProcess->updated_by = $request->user()->id;
        $lifecycleProcess->save();
        $lifecycleProcess->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_process.cancelled',
            module: 'lifecycle',
            tenantId: $lifecycleProcess->tenant_id,
            auditableType: LifecycleProcess::class,
            auditableId: $lifecycleProcess->id,
            description: 'Lifecycle process cancelled/archived.',
            newValues: ['status' => $lifecycleProcess->status->value],
            metadata: ['employee_id' => $lifecycleProcess->employee_id, 'type' => $lifecycleProcess->type->value],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Lifecycle process cancelled.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    private function ensureBelongsToCurrentTenant(LifecycleProcess $process): void
    {
        abort_unless($process->tenant_id === app(Tenant::class)->id, 404);
    }

    /**
     * Visibility gate for show() — 404 (not 403), same "don't reveal
     * existence" posture LeaveRequestController::ensureCanView() uses,
     * for a caller with no legitimate path to this specific process even
     * though they hold lifecycle.view generally.
     */
    private function ensureCanAccess(Request $request, LifecycleProcess $process): void
    {
        abort_unless(app(LifecycleVisibilityService::class)->canAccessProcess($request->user(), $process), 404);
    }
}
