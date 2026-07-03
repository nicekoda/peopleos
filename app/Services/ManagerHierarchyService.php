<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reusable manager-relationship logic — the single place future modules
 * (leave approval scoping, performance/probation reviews, onboarding
 * tasks, team dashboards, org chart) should ask "who manages whom,"
 * rather than each reimplementing its own chain walk. See
 * docs/architecture.md for the full rationale.
 */
class ManagerHierarchyService
{
    /**
     * Hard cap on how many hops up/down a chain this service will walk
     * before concluding the chain itself can't be trusted (corrupted or
     * already-cyclic data) and failing closed. This is a safety net, not
     * an expected real org depth — see EmployeeHierarchyController's own,
     * much smaller, reporting-tree *display* depth cap, which is a
     * separate concern (UX/performance, not corruption detection).
     */
    private const MAX_CHAIN_WALK = 100;

    /**
     * Would assigning $prospectiveManager as $employee's manager create a
     * cycle, directly or indirectly?
     *
     * Fails closed — returns true ("would create a cycle," block the
     * assignment) — not just for an actual cycle, but for any hierarchy
     * state above $prospectiveManager that can't be trusted: a repeated
     * employee ID (the chain is already cyclic), a chain deeper than
     * MAX_CHAIN_WALK, a manager belonging to a different tenant, a
     * soft-deleted manager, or a non-active manager anywhere in the
     * chain. Manager assignment must never proceed on top of a
     * hierarchy state this service can't vouch for. See
     * docs/security.md.
     *
     * Walks with all global scopes removed (bypassing both
     * BelongsToTenant's tenant scope and SoftDeletes) so a cross-tenant
     * or soft-deleted employee anywhere in the chain is actually seen
     * and rejected, rather than silently disappearing from a normally-
     * scoped query and truncating the walk early.
     */
    public function wouldCreateCycle(Employee $employee, Employee $prospectiveManager): bool
    {
        if ($employee->id === $prospectiveManager->id) {
            return true;
        }

        $visited = [];
        $currentId = $prospectiveManager->id;
        $hops = 0;

        while ($currentId !== null) {
            if (++$hops > self::MAX_CHAIN_WALK) {
                return true;
            }

            if (in_array($currentId, $visited, true)) {
                return true;
            }

            $visited[] = $currentId;

            if ($currentId === $employee->id) {
                return true;
            }

            /** @var Employee|null $current */
            $current = Employee::withoutGlobalScopes()->find($currentId);

            if ($current === null) {
                // Dangling reference (shouldn't happen given SET NULL on
                // delete, but the walk must not assume it can't) — no
                // further chain to check, and nothing above this point
                // was found untrustworthy.
                return false;
            }

            if ($current->tenant_id !== $employee->tenant_id) {
                return true;
            }

            if ($current->trashed()) {
                return true;
            }

            if ($current->status !== EmployeeStatus::Active) {
                return true;
            }

            $currentId = $current->manager_employee_id;
        }

        return false;
    }

    /**
     * Is $managerEmployee anywhere above $employee in the management
     * chain (direct or indirect)? Fails closed to false — an
     * untrustworthy chain must never be treated as confirming a
     * management relationship, since future callers (leave approval
     * scoping, etc.) use this to grant authorization.
     */
    public function isManagerOf(Employee $managerEmployee, Employee $employee): bool
    {
        $visited = [];
        $currentId = $employee->manager_employee_id;
        $hops = 0;

        while ($currentId !== null) {
            if (++$hops > self::MAX_CHAIN_WALK || in_array($currentId, $visited, true)) {
                return false;
            }

            $visited[] = $currentId;

            if ($currentId === $managerEmployee->id) {
                return true;
            }

            $current = Employee::withoutGlobalScopes()->find($currentId);

            if ($current === null || $current->tenant_id !== $employee->tenant_id || $current->trashed()) {
                return false;
            }

            $currentId = $current->manager_employee_id;
        }

        return false;
    }

    public function directlyManages(Employee $managerEmployee, Employee $employee): bool
    {
        return $employee->manager_employee_id === $managerEmployee->id;
    }

    /**
     * @return Collection<int, Employee>
     */
    public function directReportsOf(Employee $managerEmployee)
    {
        return Employee::query()->where('manager_employee_id', $managerEmployee->id)->get();
    }
}
