<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Deliberately simple this checkpoint — welcome message, linked-
     * employee status, and a permission-count summary. No real
     * analytics/charts; see docs/api.md for what's explicitly deferred.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // /dashboard has no permission:{key} middleware (there's nothing
        // to gate — it's the landing page every authenticated user
        // reaches), so it's the one authenticated route that doesn't
        // already fail closed for inactive users/tenants via
        // hasPermission(). Explicit here instead, same fail-closed rule.
        abort_unless($user->isActive(), 403, 'Your account is not active.');
        abort_unless($user->is_platform_admin || ($user->tenant && $user->tenant->isActive()), 403, 'Your organisation is not currently active.');

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
