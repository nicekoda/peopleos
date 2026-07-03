<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes — no employee data is ever passed as a
 * shared/page prop here. show()/edit() pass only the (already tenant-
 * scoped, via route-model-binding) employeeId; each page component
 * fetches the actual record client-side from the same /api/v1/employees
 * endpoints already built and tested in Checkpoints 6/7/11/13. See
 * docs/architecture.md for why (Checkpoint 17).
 */
class EmployeeUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Employees/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Employees/Create');
    }

    /**
     * Refinement 1: route-model-binding already scopes {employee} to the
     * current tenant (BelongsToTenant's global scope), so a cross-tenant
     * ID never resolves here at all — Laravel throws ModelNotFoundException
     * before this method runs, rendering a plain 404. The explicit
     * tenant check below is defense in depth, matching the same
     * "don't rely solely on the global scope" principle every API
     * controller in this app already follows — and it matters here
     * specifically because only the ID (never employee data) is passed
     * onward, so there's nothing to leak even if this check were somehow
     * bypassed.
     */
    public function show(Employee $employee): Response
    {
        $this->ensureBelongsToCurrentTenant($employee);

        return Inertia::render('Employees/Show', ['employeeId' => $employee->id]);
    }

    public function edit(Employee $employee): Response
    {
        $this->ensureBelongsToCurrentTenant($employee);

        return Inertia::render('Employees/Edit', ['employeeId' => $employee->id]);
    }

    protected function ensureBelongsToCurrentTenant(Employee $employee): void
    {
        abort_unless($employee->tenant_id === app(Tenant::class)->id, 404);
    }
}
