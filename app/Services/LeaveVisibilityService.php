<?php

namespace App\Services;

use App\Models\User;

/**
 * Extracted from LeaveRequestController (Checkpoint 12/14) so the same
 * safe visibility scoping can be reused outside the leave module itself
 * — the Dashboard summary (Checkpoint 21) needs the exact same "which
 * employee_ids can this user see leave for" answer, and duplicating the
 * logic would risk it silently drifting out of sync with the real rule.
 * Behavior is unchanged from the original private method — this is a
 * pure extraction, not a redesign.
 */
class LeaveVisibilityService
{
    /**
     * The set of employee_id values the caller may see, excluding the
     * leave.view_all (tenant-wide) case, which callers must check
     * separately before falling back to this. Always includes the
     * caller's own linked employee (leave.view's baseline). leave.view_team
     * adds direct reports only — Checkpoint 14's explicit "direct
     * reports only" scope decision, via
     * ManagerHierarchyService::directReportsOf(), not the full
     * reporting tree.
     *
     * @return list<string>
     */
    public function visibleEmployeeIds(User $user): array
    {
        $employee = $user->employee;

        if ($employee === null) {
            return [];
        }

        $ids = [$employee->id];

        if ($user->hasPermission('leave.view_team')) {
            $reportIds = app(ManagerHierarchyService::class)->directReportsOf($employee)->pluck('id')->all();
            $ids = array_merge($ids, $reportIds);
        }

        return $ids;
    }
}
