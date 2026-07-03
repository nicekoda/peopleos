<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\LinkEmployeeUserRequest;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Linking a user account to an employee record is deliberately a
 * separate, single-purpose controller from EmployeeController — it's a
 * distinct, more security-sensitive concern (identity, not HR data
 * editing), with its own permissions (employees.link_user/unlink_user).
 * Linking never touches roles or permissions — it cannot escalate a
 * user's access on its own; RBAC stays entirely separate.
 */
class EmployeeUserLinkController extends Controller
{
    public function store(LinkEmployeeUserRequest $request, Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $userId = $request->validated('user_id');

        $employee->update([
            'user_id' => $userId,
            'linked_at' => now(),
            'linked_by' => $request->user()->id,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee.user_linked',
            module: 'employees',
            tenantId: $employee->tenant_id,
            auditableType: Employee::class,
            auditableId: $employee->id,
            targetUserId: $userId,
            description: "User #{$userId} linked to employee #{$employee->id}.",
            metadata: ['employee_id' => $employee->id, 'linked_user_id' => $userId, 'linked_by' => $request->user()->id],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'User linked.']);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $previousUserId = $employee->user_id;

        abort_if($previousUserId === null, 404, 'This employee has no linked user to unlink.');

        $employee->update([
            'user_id' => null,
            'linked_at' => null,
            'linked_by' => null,
        ]);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee.user_unlinked',
            module: 'employees',
            tenantId: $employee->tenant_id,
            auditableType: Employee::class,
            auditableId: $employee->id,
            targetUserId: $previousUserId,
            description: "User #{$previousUserId} unlinked from employee #{$employee->id}.",
            metadata: ['employee_id' => $employee->id, 'linked_user_id' => $previousUserId],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'User unlinked.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app.
     */
    protected function ensureBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }
}
