<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\AssignManagerRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The only code path in this API that can ever write
 * employees.manager_employee_id — see AssignManagerRequest and
 * ManagerHierarchyService for the validation this closes off
 * everywhere else. See docs/security.md.
 */
class EmployeeManagerController extends Controller
{
    public function update(AssignManagerRequest $request, Employee $employee): EmployeeResource
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $oldManagerId = $employee->manager_employee_id;
        $newManagerId = $request->validated('manager_employee_id');

        $employee->manager_employee_id = $newManagerId;
        $employee->updated_by = $request->user()->id;
        $employee->save();

        $action = $oldManagerId === null ? 'employee.manager_assigned' : 'employee.manager_changed';

        AuditLogger::logFor(
            actor: $request->user(),
            action: $action,
            module: 'employees',
            tenantId: $employee->tenant_id,
            auditableType: Employee::class,
            auditableId: $employee->id,
            description: "Manager {$this->describeChange($oldManagerId)} for employee '{$employee->fullName()}' (#{$employee->employee_number}).",
            // Safe metadata only — IDs, not names/emails/phone numbers.
            metadata: [
                'employee_id' => $employee->id,
                'old_manager_employee_id' => $oldManagerId,
                'new_manager_employee_id' => $newManagerId,
                'tenant_id' => $employee->tenant_id,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new EmployeeResource($employee);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $oldManagerId = $employee->manager_employee_id;

        abort_if($oldManagerId === null, 404, 'This employee has no manager assigned.');

        $employee->manager_employee_id = null;
        $employee->updated_by = $request->user()->id;
        $employee->save();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee.manager_removed',
            module: 'employees',
            tenantId: $employee->tenant_id,
            auditableType: Employee::class,
            auditableId: $employee->id,
            description: "Manager removed for employee '{$employee->fullName()}' (#{$employee->employee_number}).",
            metadata: [
                'employee_id' => $employee->id,
                'old_manager_employee_id' => $oldManagerId,
                'new_manager_employee_id' => null,
                'tenant_id' => $employee->tenant_id,
            ],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Manager removed.']);
    }

    private function describeChange(?string $oldManagerId): string
    {
        return $oldManagerId === null ? 'assigned' : 'changed';
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403.
     */
    protected function ensureBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }
}
