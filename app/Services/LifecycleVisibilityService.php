<?php

namespace App\Services;

use App\Models\LifecycleProcess;
use App\Models\LifecycleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Checkpoint 33 — resolves "which lifecycle processes/tasks can this
 * caller see or act on" the same way LeaveVisibilityService resolves
 * leave visibility: one reusable place, not re-derived per controller
 * action.
 *
 * Unlike Leave (which has three distinct permission keys — leave.view,
 * leave.view_team, leave.view_all — for three visibility tiers), the
 * approved lifecycle permission set is deliberately generic: every role
 * holds only lifecycle.view plus whichever action permissions it needs.
 * Two roles (Line Manager, Employee) end up with the *identical*
 * permission set (lifecycle.view + lifecycle.complete_task, nothing
 * else) despite needing different visibility — Line Manager sees their
 * direct reports' processes, Employee sees only tasks assigned to them.
 * Since no permission key distinguishes them, visibility here is
 * resolved from relationship data instead: holding any *write*
 * permission on this resource (create/update/delete/assign_task) means
 * "HR/Admin tier — see everything"; holding lifecycle.view but no
 * complete_task at all means "Auditor — read-only, see everything";
 * anything left over (view + complete_task, nothing else) is the
 * narrowed tier, scoped to the caller's own direct reports and/or tasks
 * assigned directly to them.
 */
class LifecycleVisibilityService
{
    private const ADMIN_TIER_PERMISSIONS = [
        'lifecycle.create',
        'lifecycle.update',
        'lifecycle.delete',
        'lifecycle.assign_task',
    ];

    /**
     * True if the caller should see every lifecycle process/task in the
     * tenant, rather than a scoped subset.
     */
    public function hasUnrestrictedAccess(User $user): bool
    {
        foreach (self::ADMIN_TIER_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        // Auditor: lifecycle.view only, no complete_task at all — a
        // read-only tenant-wide viewer, not a restricted one.
        return ! $user->hasPermission('lifecycle.complete_task');
    }

    /**
     * Scopes a process query to what the caller may see, when they are
     * NOT unrestricted (callers must check hasUnrestrictedAccess() first
     * and skip calling this at all in that case — an unrestricted caller
     * gets the query untouched).
     */
    public function scopeProcessesQuery(User $user, Builder $query): Builder
    {
        $directReportIds = $this->directReportEmployeeIds($user);

        return $query->where(function (Builder $q) use ($user, $directReportIds) {
            if ($directReportIds !== []) {
                $q->orWhereIn('employee_id', $directReportIds);
            }

            $q->orWhereHas('tasks', function (Builder $taskQuery) use ($user) {
                $taskQuery->where('assigned_to_user_id', $user->id);
            });
        });
    }

    public function canAccessProcess(User $user, LifecycleProcess $process): bool
    {
        if ($this->hasUnrestrictedAccess($user)) {
            return true;
        }

        if (in_array($process->employee_id, $this->directReportEmployeeIds($user), true)) {
            return true;
        }

        return $process->tasks()->where('assigned_to_user_id', $user->id)->exists();
    }

    public function canAccessTask(User $user, LifecycleTask $task): bool
    {
        if ($this->hasUnrestrictedAccess($user)) {
            return true;
        }

        if ($task->assigned_to_user_id === $user->id) {
            return true;
        }

        $process = $task->relationLoaded('process') ? $task->process : $task->process()->first();

        return $process !== null && in_array($process->employee_id, $this->directReportEmployeeIds($user), true);
    }

    /**
     * @return list<string>
     */
    private function directReportEmployeeIds(User $user): array
    {
        $employee = $user->employee;

        if ($employee === null) {
            return [];
        }

        return app(ManagerHierarchyService::class)->directReportsOf($employee)->pluck('id')->all();
    }
}
