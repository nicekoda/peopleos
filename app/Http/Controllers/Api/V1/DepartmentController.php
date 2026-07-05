<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DepartmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Checkpoint 32 — Department already uses BelongsToTenant (Checkpoint
 * 26), the standard two-layer tenant pattern: the global scope filters
 * every query automatically, and ensureBelongsToCurrentTenant() below
 * is defense in depth on top of it, not the primary boundary (unlike
 * User/Role/AuditLog, which have no global scope at all). Same shape
 * as DocumentCategoryController — see docs/architecture.md.
 */
class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $departments = Department::query()->orderBy('name')->paginate();

        return DepartmentResource::collection($departments);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['tenant_id'] = app(Tenant::class)->id;
        $validated['slug'] = $this->uniqueSlugFor($validated['name'], $validated['tenant_id']);
        $validated['status'] = DepartmentStatus::Active->value;
        $validated['created_by'] = $request->user()->id;
        $validated['updated_by'] = $request->user()->id;

        $department = Department::query()->create($validated);

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'department.created',
            module: 'employees',
            tenantId: $department->tenant_id,
            auditableType: Department::class,
            auditableId: $department->id,
            description: "Department '{$department->name}' created.",
            newValues: $department->only(['name', 'slug', 'description', 'status']),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function show(Request $request, Department $department): DepartmentResource
    {
        $this->ensureBelongsToCurrentTenant($department);

        return new DepartmentResource($department);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        $this->ensureBelongsToCurrentTenant($department);

        $originalValues = $department->getOriginal();

        $department->fill($request->validated());
        $department->updated_by = $request->user()->id;
        $department->save();

        $changes = $department->getChanges();
        unset($changes['updated_at'], $changes['updated_by']);

        if ($changes !== []) {
            AuditLogger::logFor(
                actor: $request->user(),
                action: 'department.updated',
                module: 'employees',
                tenantId: $department->tenant_id,
                auditableType: Department::class,
                auditableId: $department->id,
                description: "Department '{$department->name}' updated.",
                oldValues: array_intersect_key($originalValues, $changes),
                newValues: $changes,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return new DepartmentResource($department);
    }

    /**
     * Soft delete only — there is no hard-delete code path. Employees
     * already assigned to an archived department keep their
     * department_id untouched (nullOnDelete only applies to a hard
     * delete, never triggered here); the archived-row exclusion added
     * to StoreEmployeeRequest/UpdateEmployeeRequest this checkpoint is
     * what stops it from being assignable to new or updated employee
     * records going forward. See docs/security.md.
     */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->ensureBelongsToCurrentTenant($department);

        $snapshot = $department->only(['name', 'slug', 'status']);

        $department->delete();

        AuditLogger::logFor(
            actor: $request->user(),
            action: 'department.archived',
            module: 'employees',
            tenantId: $department->tenant_id,
            auditableType: Department::class,
            auditableId: $department->id,
            description: "Department '{$department->name}' archived.",
            oldValues: $snapshot,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['message' => 'Department archived.']);
    }

    private function uniqueSlugFor(string $name, string $tenantId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (Department::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * Defense in depth beyond the BelongsToTenant global scope — same
     * pattern as every other controller in this app. 404, not 403:
     * don't reveal that a record exists in another tenant.
     */
    protected function ensureBelongsToCurrentTenant(Department $department): void
    {
        abort_unless($department->tenant_id === app(Tenant::class)->id, 404);
    }
}
