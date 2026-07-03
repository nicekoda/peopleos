<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeaveTypeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveType\StoreLeaveTypeRequest;
use App\Http\Requests\LeaveType\UpdateLeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveTypeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $leaveTypes = LeaveType::query()->orderBy('name')->paginate();

        return LeaveTypeResource::collection($leaveTypes);
    }

    public function store(StoreLeaveTypeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        // Model::create() doesn't backfill DB column defaults into the
        // in-memory instance for omitted attributes — explicit defaulting
        // here, the same fix already applied to DocumentCategoryController
        // in Checkpoint 9.
        $validated['status'] ??= LeaveTypeStatus::Active->value;
        $validated['is_paid'] ??= true;
        $validated['requires_approval'] ??= true;
        $validated['requires_document'] ??= false;
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $leaveType = LeaveType::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_type.created',
            module: 'leave',
            tenantId: $leaveType->tenant_id,
            auditableType: LeaveType::class,
            auditableId: $leaveType->id,
            description: "Leave type '{$leaveType->name}' created.",
            newValues: $leaveType->only([
                'name', 'slug', 'description', 'is_paid', 'requires_approval',
                'requires_document', 'max_days_per_year', 'status',
            ]),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new LeaveTypeResource($leaveType))->response()->setStatusCode(201);
    }

    public function show(Request $request, LeaveType $leaveType): LeaveTypeResource
    {
        $this->ensureBelongsToCurrentTenant($leaveType);

        return new LeaveTypeResource($leaveType);
    }

    public function update(UpdateLeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        $this->ensureBelongsToCurrentTenant($leaveType);

        $originalValues = $leaveType->getOriginal();

        $leaveType->fill($request->validated());
        $leaveType->updated_by = $request->user()->id;
        $leaveType->save();

        $changes = $leaveType->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'leave_type.updated',
                module: 'leave',
                tenantId: $leaveType->tenant_id,
                auditableType: LeaveType::class,
                auditableId: $leaveType->id,
                description: "Leave type '{$leaveType->name}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new LeaveTypeResource($leaveType);
    }

    /**
     * Soft delete only — there is no hard-delete code path in this API,
     * same reasoning as DocumentCategoryController::destroy(). A leave
     * type referenced by existing leave requests is always safe to
     * "delete" here: leave_requests.leave_type_id is untouched by a soft
     * delete, and StoreLeaveRequestRequest/UpdateLeaveRequestRequest
     * already exclude inactive/soft-deleted leave types from new/edited
     * requests, so the type simply becomes unavailable for *new* use
     * without affecting existing historical requests.
     */
    public function destroy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($leaveType);

        $snapshot = $leaveType->only(['name', 'slug', 'status']);

        $leaveType->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'leave_type.deleted',
            module: 'leave',
            tenantId: $leaveType->tenant_id,
            auditableType: LeaveType::class,
            auditableId: $leaveType->id,
            description: "Leave type '{$leaveType->name}' soft-deleted.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Leave type deleted.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    protected function ensureBelongsToCurrentTenant(LeaveType $leaveType): void
    {
        abort_unless($leaveType->tenant_id === app(Tenant::class)->id, 404);
    }
}
