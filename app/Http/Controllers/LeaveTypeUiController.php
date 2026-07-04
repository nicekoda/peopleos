<?php

namespace App\Http\Controllers;

use App\Models\LeaveType;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 25), same pattern as every other
 * module — no leave type data is ever passed as a page prop. Each page
 * fetches the actual record(s) client-side from the existing
 * /api/v1/leave-types endpoints (Checkpoint 12). LeaveType already uses
 * BelongsToTenant, so this is the standard two-layer tenant-isolation
 * pattern (global scope + explicit check).
 */
class LeaveTypeUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/LeaveTypes/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Settings/LeaveTypes/Create');
    }

    public function edit(LeaveType $leaveType): Response
    {
        $this->ensureBelongsToCurrentTenant($leaveType);

        return Inertia::render('Settings/LeaveTypes/Edit', ['leaveTypeId' => $leaveType->id]);
    }

    protected function ensureBelongsToCurrentTenant(LeaveType $leaveType): void
    {
        abort_unless($leaveType->tenant_id === app(Tenant::class)->id, 404);
    }
}
