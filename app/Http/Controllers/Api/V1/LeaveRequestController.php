<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\RejectLeaveRequestRequest;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Http\Requests\Leave\UpdateLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ManagerHierarchyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class LeaveRequestController extends Controller
{
    /**
     * Three visibility tiers, checked in order (Checkpoint 14):
     *
     * - leave.view_all: every leave request in the tenant.
     * - leave.view_team (no leave.view_all): the caller's own requests,
     *   plus their direct reports' — via ManagerHierarchyService::
     *   directReportsOf(), direct only, not the full reporting tree
     *   (see docs/security.md for why).
     * - leave.view only: own requests only (unchanged from Checkpoint 12).
     *
     * A caller with no linked employee and no leave.view_all sees an
     * empty list, not an error — there's nothing self-service to show
     * them, mirroring GET /me/employee's "safe empty response" posture
     * rather than treating "no employee" as a hard failure on a read
     * endpoint. A leave.view_team holder with no linked employee
     * likewise sees an empty list — there's no employee to resolve
     * direct reports from.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if ($user->hasPermission('leave.view_all')) {
            $leaveRequests = LeaveRequest::query()->orderByDesc('created_at')->paginate();

            return LeaveRequestResource::collection($leaveRequests);
        }

        $employeeIds = $this->visibleEmployeeIds($user);

        if ($employeeIds === []) {
            return LeaveRequestResource::collection(new LengthAwarePaginator([], 0, 15));
        }

        $leaveRequests = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('created_at')
            ->paginate();

        return LeaveRequestResource::collection($leaveRequests);
    }

    /**
     * Self-service only. There is no employee_id field anywhere in
     * StoreLeaveRequestRequest — the employee is always resolved from
     * the caller's own verified link, never from request input. A caller
     * with no linked employee cannot create a leave request at all.
     */
    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        $employeeId = $request->user()->employee?->id;

        abort_if($employeeId === null, 422, 'You have no linked employee record. Leave requests can only be created for a linked employee.');

        $validated = $request->validated();
        $totalDays = $this->calculateTotalDays($validated['start_date'], $validated['end_date']);

        $validated['employee_id'] = $employeeId;
        $validated['total_days'] = $totalDays;
        // Model::create() doesn't backfill DB column defaults into the
        // in-memory instance — explicit defaulting here, same fix as
        // LeaveTypeController/DocumentCategoryController.
        $validated['status'] = LeaveRequestStatus::Draft->value;
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $leaveRequest = LeaveRequest::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_request.created',
            module: 'leave',
            tenantId: $leaveRequest->tenant_id,
            auditableType: LeaveRequest::class,
            auditableId: $leaveRequest->id,
            description: 'Leave request created.',
            newValues: $leaveRequest->only(['leave_type_id', 'start_date', 'end_date', 'total_days', 'reason', 'status']),
            metadata: ['employee_id' => $leaveRequest->employee_id, 'leave_type_id' => $leaveRequest->leave_type_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new LeaveRequestResource($leaveRequest))->response()->setStatusCode(201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);
        $this->ensureCanView($request, $leaveRequest);

        return new LeaveRequestResource($leaveRequest);
    }

    /**
     * Draft-only, owner-only. leave_type_id/start_date/end_date/reason
     * are the only fields UpdateLeaveRequestRequest even validates —
     * status, employee_id, tenant_id, and every approval/rejection/
     * cancellation field are structurally absent from it, so no request
     * body can force them regardless of what's sent.
     */
    public function update(UpdateLeaveRequestRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);
        $this->ensureOwnLeaveRequest($request, $leaveRequest);

        abort_unless($leaveRequest->status === LeaveRequestStatus::Draft, 409, 'Only draft leave requests can be edited.');

        $originalValues = $leaveRequest->getOriginal();

        $validated = $request->validated();

        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $startDate = $validated['start_date'] ?? $leaveRequest->start_date->toDateString();
            $endDate = $validated['end_date'] ?? $leaveRequest->end_date->toDateString();
            $validated['total_days'] = $this->calculateTotalDays($startDate, $endDate);
        }

        $leaveRequest->fill($validated);
        $leaveRequest->updated_by = $request->user()->id;
        $leaveRequest->save();

        $changes = $leaveRequest->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'leave_request.updated',
                module: 'leave',
                tenantId: $leaveRequest->tenant_id,
                auditableType: LeaveRequest::class,
                auditableId: $leaveRequest->id,
                description: 'Leave request updated.',
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                metadata: ['employee_id' => $leaveRequest->employee_id, 'leave_type_id' => $leaveRequest->leave_type_id],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LeaveRequestResource($leaveRequest);
    }

    public function submit(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);
        $this->ensureOwnLeaveRequest($request, $leaveRequest);
        $this->ensureTransitionAllowed($leaveRequest, LeaveRequestStatus::Pending);

        $leaveRequest->update([
            'status' => LeaveRequestStatus::Pending,
            'submitted_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_request.submitted',
            module: 'leave',
            tenantId: $leaveRequest->tenant_id,
            auditableType: LeaveRequest::class,
            auditableId: $leaveRequest->id,
            description: 'Leave request submitted for approval.',
            newValues: ['status' => LeaveRequestStatus::Pending->value],
            metadata: ['employee_id' => $leaveRequest->employee_id, 'leave_type_id' => $leaveRequest->leave_type_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new LeaveRequestResource($leaveRequest->fresh());
    }

    /**
     * Manager-hierarchy-scoped as of Checkpoint 14. Holding leave.approve
     * is necessary but no longer sufficient — see resolveApprovalScope().
     * Self-block is checked first (before scope resolution), so it
     * applies uniformly to both hr_admin and direct_manager callers —
     * matters most for a dual-role Tenant Admin/HR Manager/Line Manager
     * who is also a linked employee themselves (Refinement 3).
     */
    public function approve(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);
        $this->ensureNotOwnRequestForApprovalAction($request, $leaveRequest);
        $scope = $this->resolveApprovalScope($request->user(), $leaveRequest);
        abort_if($scope === null, 403, 'You are not authorized to approve this leave request.');
        $this->ensureTransitionAllowed($leaveRequest, LeaveRequestStatus::Approved);

        $oldStatus = $leaveRequest->status->value;

        $leaveRequest->update([
            'status' => LeaveRequestStatus::Approved,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_request.approved',
            module: 'leave',
            tenantId: $leaveRequest->tenant_id,
            auditableType: LeaveRequest::class,
            auditableId: $leaveRequest->id,
            description: "Leave request approved ({$scope}).",
            newValues: ['status' => LeaveRequestStatus::Approved->value],
            metadata: $this->approvalAuditMetadata($request, $leaveRequest, $scope, $oldStatus, LeaveRequestStatus::Approved->value),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new LeaveRequestResource($leaveRequest->fresh());
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);
        $this->ensureNotOwnRequestForApprovalAction($request, $leaveRequest);
        $scope = $this->resolveApprovalScope($request->user(), $leaveRequest);
        abort_if($scope === null, 403, 'You are not authorized to reject this leave request.');
        $this->ensureTransitionAllowed($leaveRequest, LeaveRequestStatus::Rejected);

        $oldStatus = $leaveRequest->status->value;

        $leaveRequest->update([
            'status' => LeaveRequestStatus::Rejected,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'rejection_reason' => $request->validated('rejection_reason'),
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_request.rejected',
            module: 'leave',
            tenantId: $leaveRequest->tenant_id,
            auditableType: LeaveRequest::class,
            auditableId: $leaveRequest->id,
            description: "Leave request rejected ({$scope}).",
            // rejection_reason is deliberately included here — it's
            // auto-masked by AuditLogger's sensitive-key patterns (see
            // app/Services/Audit/AuditLogger.php), the same defense-in-
            // depth posture used everywhere else, not caller discipline.
            // approval_scope/old_status/new_status live in metadata
            // (never masked, never sensitive) — see
            // approvalAuditMetadata().
            newValues: $leaveRequest->only(['status', 'rejection_reason']),
            metadata: $this->approvalAuditMetadata($request, $leaveRequest, $scope, $oldStatus, LeaveRequestStatus::Rejected->value),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new LeaveRequestResource($leaveRequest->fresh());
    }

    /**
     * Determines whether the caller may approve/reject this specific
     * request, and under which scope — for authorization *and* for the
     * audit trail's approval_scope field:
     *
     * - 'hr_admin': the caller holds leave.view_all (tenant-wide HR/
     *   Admin authority). leave.approve/leave.reject alone (route
     *   middleware) is necessary but not sufficient — see
     *   docs/security.md for why this changed in Checkpoint 14.
     * - 'direct_manager': the caller has a linked employee (Refinement
     *   2 — role alone is never enough) who directly manages the
     *   request's employee, per ManagerHierarchyService::directlyManages().
     *   Indirect reports are deliberately out of scope this checkpoint.
     * - null: neither applies — caller is not authorized, regardless of
     *   what permission got them past route middleware.
     */
    protected function resolveApprovalScope(User $user, LeaveRequest $leaveRequest): ?string
    {
        if ($user->hasPermission('leave.view_all')) {
            return 'hr_admin';
        }

        $managerEmployee = $user->employee;

        if ($managerEmployee !== null && app(ManagerHierarchyService::class)->directlyManages($managerEmployee, $leaveRequest->employee)) {
            return 'direct_manager';
        }

        return null;
    }

    /**
     * Safe metadata only (Refinement 5) — IDs, scope, and status
     * strings, never the request's reason/rejection_reason text.
     *
     * @return array<string, mixed>
     */
    protected function approvalAuditMetadata(Request $request, LeaveRequest $leaveRequest, string $scope, string $oldStatus, string $newStatus): array
    {
        return [
            'leave_request_id' => $leaveRequest->id,
            'employee_id' => $leaveRequest->employee_id,
            'leave_type_id' => $leaveRequest->leave_type_id,
            'actor_user_id' => $request->user()->id,
            'actor_employee_id' => $request->user()->employee?->id,
            'approval_scope' => $scope,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ];
    }

    /**
     * Strictly self-only, regardless of role — even though Tenant Admin/
     * HR Manager also hold leave.cancel per the suggested role mapping,
     * there is no "cancel on behalf of" capability built this checkpoint
     * (unlike policies.assign for policy acknowledgement). See
     * docs/security.md.
     */
    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);
        $this->ensureOwnLeaveRequest($request, $leaveRequest);
        $this->ensureTransitionAllowed($leaveRequest, LeaveRequestStatus::Cancelled);

        $leaveRequest->update([
            'status' => LeaveRequestStatus::Cancelled,
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_request.cancelled',
            module: 'leave',
            tenantId: $leaveRequest->tenant_id,
            auditableType: LeaveRequest::class,
            auditableId: $leaveRequest->id,
            description: 'Leave request cancelled.',
            newValues: ['status' => LeaveRequestStatus::Cancelled->value],
            metadata: ['employee_id' => $leaveRequest->employee_id, 'leave_type_id' => $leaveRequest->leave_type_id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new LeaveRequestResource($leaveRequest->fresh());
    }

    /**
     * Calendar days, inclusive of both endpoints — not business days.
     * Weekends and public holidays are counted. See docs/security.md
     * "Current limitations" for the documented gap and future direction
     * (business-day calculation, holiday calendars, half-day leave).
     */
    private function calculateTotalDays(string $startDate, string $endDate): int
    {
        return (int) Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    protected function ensureBelongsToCurrentTenant(LeaveRequest $leaveRequest): void
    {
        abort_unless($leaveRequest->tenant_id === app(Tenant::class)->id, 404);
    }

    /**
     * Visibility gate for show(): own request, leave.view_all (tenant-
     * wide), or leave.view_team + the request belongs to one of the
     * caller's direct reports. 404 (not 403) for a caller with no
     * legitimate visibility path at all — same "don't reveal existence"
     * posture used for cross-tenant access.
     */
    protected function ensureCanView(Request $request, LeaveRequest $leaveRequest): void
    {
        $user = $request->user();

        if ($user->hasPermission('leave.view_all')) {
            return;
        }

        abort_unless(in_array($leaveRequest->employee_id, $this->visibleEmployeeIds($user), true), 404);
    }

    /**
     * The set of employee_id values the caller may see, excluding the
     * leave.view_all (tenant-wide) case, which is checked separately by
     * callers before reaching here. Always includes the caller's own
     * linked employee (leave.view's baseline). leave.view_team adds
     * direct reports only — Refinement 1/Checkpoint 14's explicit
     * "direct reports only" scope decision, via
     * ManagerHierarchyService::directReportsOf(), not the full
     * reporting tree.
     *
     * @return list<string>
     */
    protected function visibleEmployeeIds(User $user): array
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

    /**
     * Ownership gate for self-service actions (update/submit/cancel).
     * 403, not 404: an HR/Admin caller with leave.view_all can already
     * see this resource exists (via show()/index()) — hiding it here
     * would be misleading, not a real IDOR protection. This is "you can
     * see it, you're just not the owner," a policy decision, not an
     * existence-hiding one.
     */
    protected function ensureOwnLeaveRequest(Request $request, LeaveRequest $leaveRequest): void
    {
        $ownEmployeeId = $request->user()->employee?->id;

        abort_unless(
            $ownEmployeeId !== null && $leaveRequest->employee_id === $ownEmployeeId,
            403,
            'This action is restricted to the employee who owns the leave request.',
        );
    }

    /**
     * Blocks self-approval/self-rejection, independent of whatever
     * permission the caller holds — matters most for Tenant Admin/HR
     * Manager users who may also be linked employees themselves.
     */
    protected function ensureNotOwnRequestForApprovalAction(Request $request, LeaveRequest $leaveRequest): void
    {
        $ownEmployeeId = $request->user()->employee?->id;

        abort_if(
            $ownEmployeeId !== null && $leaveRequest->employee_id === $ownEmployeeId,
            403,
            'You cannot approve or reject your own leave request.',
        );
    }

    /**
     * The single enforcement point for status transitions — every
     * write action (submit/approve/reject/cancel) routes through this,
     * so "approved -> pending", "rejected -> approved", double-approval,
     * etc. are all rejected the same way, not re-implemented per action.
     * 409, not 422: this is a state conflict, not a validation failure
     * of the request body — same distinction PolicyController::acknowledge()
     * already makes for a superseded policy version.
     */
    protected function ensureTransitionAllowed(LeaveRequest $leaveRequest, LeaveRequestStatus $target): void
    {
        abort_unless(
            $leaveRequest->status->canTransitionTo($target),
            409,
            "Cannot transition leave request from '{$leaveRequest->status->value}' to '{$target->value}'.",
        );
    }
}
