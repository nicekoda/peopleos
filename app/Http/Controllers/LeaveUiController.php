<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes — same pattern as EmployeeUiController
 * (Checkpoint 17). No leave request data is ever passed as a shared/
 * page prop; show() passes only the (already tenant-scoped, via
 * route-model-binding) leaveRequestId. Each page component fetches the
 * actual record client-side from the existing, already-tested
 * /api/v1/leave-requests, /api/v1/leave-types, and
 * /api/v1/me/leave-balances endpoints. See docs/architecture.md.
 */
class LeaveUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Leave/Index');
    }

    public function create(): Response
    {
        return Inertia::render('Leave/Create');
    }

    /**
     * Route-model-binding already scopes {leaveRequest} to the current
     * tenant (BelongsToTenant's global scope) — a cross-tenant ID never
     * resolves here at all, rendering a plain 404 before this method
     * runs. The explicit check below is defense in depth, matching
     * EmployeeUiController::show() exactly.
     */
    public function show(LeaveRequest $leaveRequest): Response
    {
        $this->ensureBelongsToCurrentTenant($leaveRequest);

        return Inertia::render('Leave/Show', ['leaveRequestId' => $leaveRequest->id]);
    }

    protected function ensureBelongsToCurrentTenant(LeaveRequest $leaveRequest): void
    {
        abort_unless($leaveRequest->tenant_id === app(Tenant::class)->id, 404);
    }
}
