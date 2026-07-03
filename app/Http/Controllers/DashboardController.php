<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Welcome message, linked-employee status, a permission-count
     * summary, and (Checkpoint 21) real module summary cards fetched
     * client-side from /api/v1/dashboard. No real analytics/charts; see
     * docs/api.md for what's explicitly deferred.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // /dashboard has no blanket permission:{key} middleware — it
        // can't, since dashboard.view is a tenant-scoped permission a
        // Platform Super Admin could never hold, and they must still
        // reach this page (with a safe platform-only view, never fake
        // tenant data). Explicit checks here instead, same fail-closed
        // rule hasPermission() already enforces everywhere else.
        abort_unless($user->isActive(), 403, 'Your account is not active.');
        abort_unless($user->is_platform_admin || ($user->tenant && $user->tenant->isActive()), 403, 'Your organisation is not currently active.');
        abort_unless($user->is_platform_admin || $user->hasPermission('dashboard.view'), 403, 'You do not have access to the dashboard.');

        $employee = $user->employee;

        return Inertia::render('Dashboard', [
            'linkedEmployee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->fullName(),
                'status' => $employee->status->value,
            ] : null,
            'permissionCount' => count($user->permissionKeys()),
        ]);
    }
}
