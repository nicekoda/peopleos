<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin Inertia page routes (Checkpoint 24), same pattern as every other
 * module — no audit log data is ever passed as a page prop. Each page
 * fetches the actual record(s) client-side from the new
 * /api/v1/audit-logs endpoints. Because AuditLog does not use
 * BelongsToTenant (see docs/security.md), show() adds the same
 * explicit tenant check the API layer relies on — this is the primary
 * tenant boundary here, not defense-in-depth on top of a scope.
 */
class AuditLogUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/AuditLogs');
    }

    public function show(AuditLog $auditLog): Response
    {
        $this->ensureBelongsToCurrentTenant($auditLog);

        return Inertia::render('Settings/AuditLogShow', ['auditLogId' => $auditLog->id]);
    }

    protected function ensureBelongsToCurrentTenant(AuditLog $auditLog): void
    {
        abort_unless($auditLog->tenant_id === app(Tenant::class)->id, 404);
    }
}
