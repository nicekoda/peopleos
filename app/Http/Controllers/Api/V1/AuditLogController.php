<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Read-only (Checkpoint 24) — index()/show() only, no store/update/
 * destroy anywhere; AuditLog is already structurally append-only at the
 * model layer regardless (save() on an existing row and delete() both
 * throw). AuditLog, like User/Role (Checkpoint 23), does not use
 * BelongsToTenant — every query here manually filters by tenant_id;
 * this is the primary tenant boundary, not defense-in-depth on top of
 * a global scope. See docs/security.md.
 */
class AuditLogController extends Controller
{
    private const SEVERITIES = ['info', 'warning', 'critical'];

    /**
     * permission:audit.view middleware already blocks Platform Super
     * Admins (a tenant-scoped permission they can never hold) — this
     * explicit check is defense in depth, and matters concretely:
     * app(Tenant::class) is never bound for a platform admin, so an
     * unfiltered query here would otherwise span every tenant.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_if($request->user()->is_platform_admin, 403, 'Audit logs are tenant-scoped only.');

        $validated = $request->validate([
            'module' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:150'],
            'severity' => ['nullable', Rule::in(self::SEVERITIES)],
            'actor_user_id' => ['nullable', 'integer'],
            'target_user_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $logs = AuditLog::query()
            ->where('tenant_id', app(Tenant::class)->id)
            ->when($validated['module'] ?? null, fn ($query, $value) => $query->where('module', $value))
            ->when($validated['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($validated['severity'] ?? null, fn ($query, $value) => $query->where('severity', $value))
            ->when($validated['actor_user_id'] ?? null, fn ($query, $value) => $query->where('actor_user_id', $value))
            ->when($validated['target_user_id'] ?? null, fn ($query, $value) => $query->where('target_user_id', $value))
            ->when($validated['date_from'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '>=', $value))
            ->when($validated['date_to'] ?? null, fn ($query, $value) => $query->whereDate('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->paginate();

        return AuditLogResource::collection($logs);
    }

    public function show(Request $request, AuditLog $auditLog): AuditLogResource
    {
        abort_if($request->user()->is_platform_admin, 403, 'Audit logs are tenant-scoped only.');

        $this->ensureBelongsToCurrentTenant($auditLog);

        return new AuditLogResource($auditLog);
    }

    /**
     * Defense in depth beyond the manual tenant filter above — same
     * pattern as every other controller in this app, applied here as
     * the primary (not secondary) safeguard since AuditLog has no
     * global scope. A platform-level log (tenant_id null) never
     * matches a real tenant's id, so this also naturally rejects those.
     */
    protected function ensureBelongsToCurrentTenant(AuditLog $auditLog): void
    {
        abort_unless($auditLog->tenant_id === app(Tenant::class)->id, 404);
    }
}
