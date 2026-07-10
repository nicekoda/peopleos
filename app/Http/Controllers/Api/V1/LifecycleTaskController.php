<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LifecycleTaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lifecycle\ReorderLifecycleTasksRequest;
use App\Http\Requests\Lifecycle\StoreLifecycleTaskRequest;
use App\Http\Requests\Lifecycle\UpdateLifecycleTaskRequest;
use App\Http\Resources\LifecycleTaskResource;
use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\LifecycleVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class LifecycleTaskController extends Controller
{
    /**
     * lifecycle.create (route middleware) covers adding a task at all;
     * assigning it to someone at creation time additionally requires
     * lifecycle.assign_task — a role holding create but not assign_task
     * can still add an unassigned task, just not assign it directly. No
     * role in this checkpoint's approved grants is actually in that
     * position (every role holding lifecycle.create also holds
     * lifecycle.assign_task), but the check is correct for any future
     * custom role that splits them.
     */
    public function store(StoreLifecycleTaskRequest $request, LifecycleProcess $lifecycleProcess): JsonResponse
    {
        $this->ensureProcessBelongsToCurrentTenant($lifecycleProcess);

        $validated = $request->validated();

        if (isset($validated['assigned_to_user_id'])) {
            abort_unless($request->user()->hasPermission('lifecycle.assign_task'), 403, 'You are not authorized to assign lifecycle tasks.');
        }

        $validated['process_id'] = $lifecycleProcess->id;
        $validated['tenant_id'] = $lifecycleProcess->tenant_id;
        $validated['status'] = LifecycleTaskStatus::Pending->value;
        // Checkpoint 45 — a manually-added task is appended to the end
        // of the process's existing list, not defaulted to 0 (which
        // would otherwise jump it ahead of every already-ordered task).
        $validated['sort_order'] = ($lifecycleProcess->tasks()->max('sort_order') ?? -1) + 1;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $task = LifecycleTask::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task.created',
            module: 'lifecycle',
            tenantId: $task->tenant_id,
            auditableType: LifecycleTask::class,
            auditableId: $task->id,
            description: 'Lifecycle task created.',
            newValues: ['process_id' => $task->process_id, 'status' => $task->status->value],
            metadata: ['process_id' => $task->process_id, 'assigned_to_user_id' => $task->assigned_to_user_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new LifecycleTaskResource($task))->response()->setStatusCode(201);
    }

    /**
     * Only Tenant Admin/HR Manager/HR Officer hold lifecycle.update — all
     * three are "unrestricted" by definition, same reasoning as
     * LifecycleProcessController::update().
     */
    public function update(UpdateLifecycleTaskRequest $request, LifecycleTask $lifecycleTask): LifecycleTaskResource
    {
        $this->ensureTaskBelongsToCurrentTenant($lifecycleTask);

        $validated = $request->validated();

        if (array_key_exists('assigned_to_user_id', $validated)) {
            abort_unless($request->user()->hasPermission('lifecycle.assign_task'), 403, 'You are not authorized to assign lifecycle tasks.');
        }

        $originalValues = $lifecycleTask->getOriginal();

        $lifecycleTask->fill($validated);
        $lifecycleTask->updated_by = $request->user()->id;
        $lifecycleTask->save();

        $changes = $lifecycleTask->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'lifecycle_task.updated',
                module: 'lifecycle',
                tenantId: $lifecycleTask->tenant_id,
                auditableType: LifecycleTask::class,
                auditableId: $lifecycleTask->id,
                description: 'Lifecycle task updated.',
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                metadata: ['process_id' => $lifecycleTask->process_id],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LifecycleTaskResource($lifecycleTask);
    }

    /**
     * Soft-delete only (Refinement 10) — removes the task from the
     * active list without destroying the row. Blocked once the parent
     * process is terminal, same rule as every other task mutation.
     */
    public function destroy(Request $request, LifecycleTask $lifecycleTask): JsonResponse
    {
        $this->ensureTaskBelongsToCurrentTenant($lifecycleTask);

        abort_if($lifecycleTask->process->status->isTerminal(), 422, 'Cannot remove a task from a completed or cancelled process.');

        $lifecycleTask->updated_by = $request->user()->id;
        $lifecycleTask->save();
        $lifecycleTask->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task.deleted',
            module: 'lifecycle',
            tenantId: $lifecycleTask->tenant_id,
            auditableType: LifecycleTask::class,
            auditableId: $lifecycleTask->id,
            description: 'Lifecycle task removed.',
            metadata: ['process_id' => $lifecycleTask->process_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Lifecycle task removed.']);
    }

    /**
     * lifecycle.complete_task (route middleware) is necessary but not
     * sufficient — Line Manager and Employee hold the identical
     * permission set, so LifecycleVisibilityService::canAccessTask()
     * resolves *which* tasks each may actually complete (their own
     * assignment, or a direct report's process). HR/Admin-tier callers
     * are unrestricted and may complete any task in the tenant.
     */
    public function complete(Request $request, LifecycleTask $lifecycleTask): LifecycleTaskResource
    {
        $this->ensureTaskBelongsToCurrentTenant($lifecycleTask);
        $this->ensureCanAccessTask($request, $lifecycleTask);

        abort_if($lifecycleTask->process->status->isTerminal(), 422, 'Cannot complete a task on a completed or cancelled process.');
        $this->ensureTransitionAllowed($lifecycleTask, LifecycleTaskStatus::Completed);

        $lifecycleTask->update([
            'status' => LifecycleTaskStatus::Completed,
            'completed_at' => now(),
            'completed_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task.completed',
            module: 'lifecycle',
            tenantId: $lifecycleTask->tenant_id,
            auditableType: LifecycleTask::class,
            auditableId: $lifecycleTask->id,
            description: 'Lifecycle task completed.',
            newValues: ['status' => LifecycleTaskStatus::Completed->value],
            metadata: ['process_id' => $lifecycleTask->process_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new LifecycleTaskResource($lifecycleTask->fresh());
    }

    public function skip(Request $request, LifecycleTask $lifecycleTask): LifecycleTaskResource
    {
        $this->ensureTaskBelongsToCurrentTenant($lifecycleTask);
        $this->ensureCanAccessTask($request, $lifecycleTask);

        abort_if($lifecycleTask->process->status->isTerminal(), 422, 'Cannot skip a task on a completed or cancelled process.');
        $this->ensureTransitionAllowed($lifecycleTask, LifecycleTaskStatus::Skipped);

        $lifecycleTask->update([
            'status' => LifecycleTaskStatus::Skipped,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task.skipped',
            module: 'lifecycle',
            tenantId: $lifecycleTask->tenant_id,
            auditableType: LifecycleTask::class,
            auditableId: $lifecycleTask->id,
            description: 'Lifecycle task skipped.',
            newValues: ['status' => LifecycleTaskStatus::Skipped->value],
            metadata: ['process_id' => $lifecycleTask->process_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new LifecycleTaskResource($lifecycleTask->fresh());
    }

    /**
     * lifecycle.update (route middleware) — same permission the process
     * edit itself requires; there is no separate "reorder" permission,
     * the same "reuse, don't invent a narrower key for a sub-action of
     * an already-covered write" reasoning as LifecycleProcessController.
     * ReorderLifecycleTasksRequest has already verified task_ids is
     * exactly this process's current task set before this runs.
     */
    public function reorder(ReorderLifecycleTasksRequest $request, LifecycleProcess $lifecycleProcess): AnonymousResourceCollection
    {
        $this->ensureProcessBelongsToCurrentTenant($lifecycleProcess);

        $taskIds = $request->validated()['task_ids'];

        DB::transaction(function () use ($taskIds, $request): void {
            foreach ($taskIds as $index => $taskId) {
                LifecycleTask::query()->where('id', $taskId)->update([
                    'sort_order' => $index,
                    'updated_by' => $request->user()->id,
                ]);
            }
        });

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'lifecycle_task.reordered',
            module: 'lifecycle',
            tenantId: $lifecycleProcess->tenant_id,
            auditableType: LifecycleProcess::class,
            auditableId: $lifecycleProcess->id,
            description: 'Lifecycle process tasks reordered.',
            metadata: ['process_id' => $lifecycleProcess->id, 'task_ids' => $taskIds],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return LifecycleTaskResource::collection($lifecycleProcess->tasks()->with('assignedToUser')->get());
    }

    private function ensureProcessBelongsToCurrentTenant(LifecycleProcess $process): void
    {
        abort_unless($process->tenant_id === app(Tenant::class)->id, 404);
    }

    private function ensureTaskBelongsToCurrentTenant(LifecycleTask $task): void
    {
        abort_unless($task->tenant_id === app(Tenant::class)->id, 404);
    }

    private function ensureCanAccessTask(Request $request, LifecycleTask $task): void
    {
        abort_unless(app(LifecycleVisibilityService::class)->canAccessTask($request->user(), $task), 403, 'You are not authorized to act on this task.');
    }

    private function ensureTransitionAllowed(LifecycleTask $task, LifecycleTaskStatus $target): void
    {
        abort_unless(
            $task->status->canTransitionTo($target),
            409,
            "Cannot transition task from '{$task->status->value}' to '{$target->value}'.",
        );
    }
}
