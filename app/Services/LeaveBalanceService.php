<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;

/**
 * The single place leave-balance mutation logic lives — reserving,
 * releasing, and consuming pending balance, plus the "is this leave
 * type even balance-controlled" question. Callers (LeaveRequestController)
 * are responsible for wrapping every call to reserve/release/consume in
 * a DB::transaction() alongside the leave request's own status update,
 * so both succeed or both roll back together (Refinement 3). This
 * service does not open transactions itself — it assumes the caller
 * already has one open and has locked the balance row (via
 * findOrCreate(), which locks internally).
 */
class LeaveBalanceService
{
    /**
     * A leave type with no annual cap configured is not balance-
     * controlled at all — no balance row is ever created or consulted
     * for it. See docs/security.md.
     */
    public function isBalanceControlled(LeaveType $leaveType): bool
    {
        return $leaveType->max_days_per_year !== null;
    }

    /**
     * Finds the employee's balance row for this leave type/year, locking
     * it (`lockForUpdate()`) so concurrent submits against the same
     * balance serialize rather than both reading a stale available_days.
     * Creates one (entitlement seeded from leave_types.max_days_per_year)
     * if none exists yet.
     *
     * Must be called from inside an open DB transaction — the caller
     * (LeaveRequestController) owns that transaction boundary, since it
     * must also cover the leave request's own status update.
     */
    public function findOrCreate(Employee $employee, LeaveType $leaveType, int $year, User $actor): LeaveBalance
    {
        $balance = $this->lockedQuery($employee, $leaveType, $year)->first();

        if ($balance !== null) {
            return $balance;
        }

        try {
            $balance = LeaveBalance::query()->create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
                'entitlement_days' => $leaveType->max_days_per_year,
                'used_days' => 0,
                'pending_days' => 0,
                'carried_forward_days' => 0,
                'adjustment_days' => 0,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        } catch (QueryException $e) {
            // Two concurrent first-ever submits for the same employee/
            // leave type/year both saw "no balance exists" before either
            // committed — the partial unique index rejects the loser.
            // Re-fetch (now locked) instead of failing the whole request.
            $balance = $this->lockedQuery($employee, $leaveType, $year)->firstOrFail();
        }

        AuditLogger::logFor(
            actor: $actor,
            action: 'leave_balance.created',
            module: 'leave',
            tenantId: $balance->tenant_id,
            auditableType: LeaveBalance::class,
            auditableId: $balance->id,
            description: 'Leave balance auto-created from leave type entitlement.',
            newValues: $balance->only(['entitlement_days', 'used_days', 'pending_days', 'carried_forward_days', 'adjustment_days']),
            metadata: [
                'leave_balance_id' => $balance->id,
                'employee_id' => $balance->employee_id,
                'leave_type_id' => $balance->leave_type_id,
                'year' => $balance->year,
            ],
        );

        return $balance;
    }

    /**
     * Reserves $days into pending_days if enough balance is available;
     * returns false (no mutation) otherwise — the caller must abort the
     * whole request (inside the same transaction) without ever updating
     * the leave request's status. Refinement 6: a reservation that would
     * push available_days negative is rejected, not clamped.
     */
    public function reservePending(LeaveBalance $balance, float $days): bool
    {
        if ($balance->availableDays() < $days) {
            return false;
        }

        $balance->pending_days = (float) $balance->pending_days + $days;
        $balance->save();

        return true;
    }

    /**
     * Moves $days from pending_days to used_days — only ever called
     * when the leave request is transitioning pending -> approved
     * (enforced by the caller checking LeaveRequestStatus::canTransitionTo()
     * before calling this, Refinement 2).
     */
    public function consumePending(LeaveBalance $balance, float $days): void
    {
        $balance->pending_days = max(0, (float) $balance->pending_days - $days);
        $balance->used_days = (float) $balance->used_days + $days;
        $balance->save();
    }

    /**
     * Releases $days from pending_days — reject/cancel of a pending
     * request. Clamped at 0 defensively (never goes negative), though
     * under correct calling discipline (Refinement 1/2 — only called
     * once per submit, only from a request that was actually pending)
     * it should always exactly zero out the reservation this request
     * made.
     */
    public function releasePending(LeaveBalance $balance, float $days): void
    {
        $balance->pending_days = max(0, (float) $balance->pending_days - $days);
        $balance->save();
    }

    private function lockedQuery(Employee $employee, LeaveType $leaveType, int $year): Builder
    {
        return LeaveBalance::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->lockForUpdate();
    }
}
