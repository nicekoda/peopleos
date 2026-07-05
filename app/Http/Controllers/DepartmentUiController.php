<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 32), same pattern as every other
 * module — no department data is ever passed as a page prop. Each page
 * fetches the actual record(s) client-side from /api/v1/departments.
 * Department already uses BelongsToTenant, so this is the standard
 * two-layer tenant-isolation pattern (global scope + explicit check).
 */
class DepartmentUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Departments/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Departments/Create');
    }

    public function edit(Department $department): Response
    {
        $this->ensureBelongsToCurrentTenant($department);

        return Inertia::render('Settings/Departments/Edit', ['departmentId' => $department->id]);
    }

    protected function ensureBelongsToCurrentTenant(Department $department): void
    {
        abort_unless($department->tenant_id === app(Tenant::class)->id, 404);
    }
}
