<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes, same pattern as EmployeeUiController/
 * LeaveUiController (Checkpoints 17/18) — no document data is ever
 * passed as a page prop. Each page fetches the actual record(s)
 * client-side from the existing /api/v1/employees/{employee}/documents
 * endpoints (Checkpoint 8). See docs/architecture.md.
 */
class EmployeeDocumentUiController extends Controller
{
    public function index(Employee $employee): Response
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);

        return Inertia::render('Employees/Documents/Index', ['employeeId' => $employee->id]);
    }

    public function create(Employee $employee): Response
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);

        return Inertia::render('Employees/Documents/Upload', ['employeeId' => $employee->id]);
    }

    /**
     * Two object-level checks, same as EmployeeDocumentController::show()
     * (Checkpoint 8): the employee must belong to the current tenant, and
     * the document must belong to *this specific employee*, not just to
     * the current tenant — a document ID valid for a different employee
     * in the same tenant must still 404 here, not just at the API layer.
     */
    public function show(Employee $employee, EmployeeDocument $document): Response
    {
        $this->ensureEmployeeBelongsToCurrentTenant($employee);
        $this->ensureDocumentBelongsToEmployee($document, $employee);

        return Inertia::render('Employees/Documents/Show', [
            'employeeId' => $employee->id,
            'documentId' => $document->id,
        ]);
    }

    protected function ensureEmployeeBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }

    protected function ensureDocumentBelongsToEmployee(EmployeeDocument $document, Employee $employee): void
    {
        abort_unless($document->employee_id === $employee->id, 404);
    }
}
