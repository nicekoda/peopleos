<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveBalance\StoreLeaveBalanceRequest;
use App\Http\Requests\LeaveBalance\UpdateLeaveBalanceRequest;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\LeaveBalance;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveBalanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $leaveBalances = LeaveBalance::query()->orderByDesc('year')->paginate();

        return LeaveBalanceResource::collection($leaveBalances);
    }

    public function store(StoreLeaveBalanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        // Model::create() doesn't backfill DB column defaults into the
        // in-memory instance — explicit defaulting here, same fix as
        // every other controller since Checkpoint 9.
        $validated['carried_forward_days'] ??= 0;
        $validated['adjustment_days'] ??= 0;
        $validated['used_days'] = 0;
        $validated['pending_days'] = 0;
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $leaveBalance = LeaveBalance::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_balance.created',
            module: 'leave',
            tenantId: $leaveBalance->tenant_id,
            auditableType: LeaveBalance::class,
            auditableId: $leaveBalance->id,
            description: 'Leave balance created.',
            newValues: $leaveBalance->only(['entitlement_days', 'used_days', 'pending_days', 'carried_forward_days', 'adjustment_days']),
            metadata: [
                'leave_balance_id' => $leaveBalance->id,
                'employee_id' => $leaveBalance->employee_id,
                'leave_type_id' => $leaveBalance->leave_type_id,
                'year' => $leaveBalance->year,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new LeaveBalanceResource($leaveBalance))->response()->setStatusCode(201);
    }

    public function show(Request $request, LeaveBalance $leaveBalance): LeaveBalanceResource
    {
        $this->ensureBelongsToCurrentTenant($leaveBalance);

        return new LeaveBalanceResource($leaveBalance);
    }

    /**
     * adjustment_days requires leave_balances.adjust in addition to the
     * route's leave_balances.update — not expressible as route
     * middleware, since it depends on which fields are present in the
     * request body. Mirrors policies.archive requiring policies.update
     * in addition (Checkpoint 10).
     *
     * Refinement 6: rejects any update that would make available_days
     * negative — computed from the *prospective* merged values, not
     * just the fields being changed in isolation.
     */
    public function update(UpdateLeaveBalanceRequest $request, LeaveBalance $leaveBalance): LeaveBalanceResource
    {
        $this->ensureBelongsToCurrentTenant($leaveBalance);

        $validated = $request->validated();

        if (array_key_exists('adjustment_days', $validated)) {
            abort_unless($request->user()->hasPermission('leave_balances.adjust'), 403, 'Adjusting leave balance requires the leave_balances.adjust permission.');
        }

        $originalValues = $leaveBalance->getOriginal();

        $prospectiveEntitlement = (float) ($validated['entitlement_days'] ?? $leaveBalance->entitlement_days);
        $prospectiveCarriedForward = (float) ($validated['carried_forward_days'] ?? $leaveBalance->carried_forward_days);
        $prospectiveAdjustment = (float) ($validated['adjustment_days'] ?? $leaveBalance->adjustment_days);
        $prospectiveAvailable = $prospectiveEntitlement + $prospectiveCarriedForward + $prospectiveAdjustment
            - (float) $leaveBalance->used_days - (float) $leaveBalance->pending_days;

        abort_if($prospectiveAvailable < 0, 422, 'This change would make the available balance negative.');

        $leaveBalance->fill($validated);
        $leaveBalance->updated_by = $request->user()->id;
        $leaveBalance->save();

        $changes = $leaveBalance->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            $action = array_key_exists('adjustment_days', $changes) ? 'leave_balance.adjusted' : 'leave_balance.updated';

            AuditLogger::logFor(
                actor: $request->user(),
                action: $action,
                module: 'leave',
                tenantId: $leaveBalance->tenant_id,
                auditableType: LeaveBalance::class,
                auditableId: $leaveBalance->id,
                description: 'Leave balance updated.',
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                metadata: [
                    'leave_balance_id' => $leaveBalance->id,
                    'employee_id' => $leaveBalance->employee_id,
                    'leave_type_id' => $leaveBalance->leave_type_id,
                    'year' => $leaveBalance->year,
                ],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LeaveBalanceResource($leaveBalance);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    protected function ensureBelongsToCurrentTenant(LeaveBalance $leaveBalance): void
    {
        abort_unless($leaveBalance->tenant_id === app(Tenant::class)->id, 404);
    }
}
