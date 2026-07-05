<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $employees = Employee::query()
            ->with(['department', 'location', 'position'])
            ->orderBy('last_name')
            ->paginate();

        return EmployeeResource::collection($employees);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['status'] ??= EmployeeStatus::Draft->value;
        $data['tenant_id'] = app(Tenant::class)->id;
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $employee = Employee::query()->create($data);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee.created',
            module: 'employees',
            tenantId: $employee->tenant_id,
            auditableType: Employee::class,
            auditableId: $employee->id,
            description: "Employee '{$employee->fullName()}' (#{$employee->employee_number}) created.",
            newValues: $employee->only([
                'employee_number', 'first_name', 'middle_name', 'last_name', 'preferred_name',
                'work_email', 'personal_email', 'phone', 'status', 'employment_type',
                'department_id', 'location_id', 'position_id', 'manager_employee_id',
                'start_date', 'probation_end_date', 'confirmation_date',
            ]),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $employee->load(['department', 'location', 'position']);

        return (new EmployeeResource($employee))->response()->setStatusCode(201);
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $employee->load(['department', 'location', 'position']);

        return new EmployeeResource($employee);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $originalValues = $employee->getOriginal();

        $employee->fill($request->validated());
        $employee->updated_by = $request->user()->id;
        $employee->save();

        $changes = $employee->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'employee.updated',
                module: 'employees',
                tenantId: $employee->tenant_id,
                auditableType: Employee::class,
                auditableId: $employee->id,
                description: "Employee '{$employee->fullName()}' (#{$employee->employee_number}) updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        $employee->load(['department', 'location', 'position']);

        return new EmployeeResource($employee);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($employee);

        $snapshot = $employee->only(['employee_number', 'first_name', 'last_name', 'status']);

        $employee->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'employee.deleted',
            module: 'employees',
            tenantId: $employee->tenant_id,
            auditableType: Employee::class,
            auditableId: $employee->id,
            description: "Employee '{$employee->fullName()}' (#{$employee->employee_number}) soft-deleted.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Employee deleted.']);
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — every
     * endpoint independently verifies tenant membership before acting on
     * a record, per the architecture principle that the global scope is
     * enforcement, not the only safeguard. 404, not 403: don't reveal
     * that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }
}
