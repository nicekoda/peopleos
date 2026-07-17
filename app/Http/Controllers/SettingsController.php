<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings landing page (Checkpoint 22) — same "access, not data"
 * two-layer design as DashboardController (Checkpoint 21):
 * tenant.settings.view only grants reaching /settings at all, never any
 * section's data. Each card the frontend renders is separately gated by
 * its own module permission (employees.view/tenant.view/roles.view/
 * etc.), checked again wherever that section's real data is fetched.
 */
class SettingsController extends Controller
{
    /**
     * No blanket permission:{key} middleware — tenant.settings.view is a
     * tenant-scoped permission a Platform Super Admin could never hold,
     * and they must still reach this page (with a safe platform-only
     * message, never fake tenant data). Explicit checks here instead,
     * same fail-closed rule already used by DashboardController.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user->isActive(), 403, 'Your account is not active.');
        abort_unless($user->is_platform_admin || ($user->tenant && $user->tenant->isActive()), 403, 'Your organisation is not currently active.');
        abort_unless($user->is_platform_admin || $user->hasPermission('tenant.settings.view'), 403, 'You do not have access to Settings.');

        return Inertia::render('Settings/Index');
    }

    /**
     * Thin page route, same pattern as every other module — tenant
     * profile data is fetched client-side from /api/v1/tenant, never
     * passed through as an Inertia prop.
     */
    public function company(): Response
    {
        return Inertia::render('Settings/Company');
    }

    /**
     * Checkpoint 47 — thin page routes, same pattern as every other
     * module — module/branding state is fetched client-side from
     * /api/v1/tenant/modules and /api/v1/tenant/branding, never passed
     * through as an Inertia prop.
     */
    public function modules(): Response
    {
        return Inertia::render('Settings/Modules');
    }

    public function branding(): Response
    {
        return Inertia::render('Settings/Branding');
    }

    /**
     * Checkpoint 48 — same thin-page pattern; field/option data is
     * fetched client-side from /api/v1/custom-fields/{entityType}.
     */
    public function customFields(): Response
    {
        return Inertia::render('Settings/CustomFields');
    }
}
