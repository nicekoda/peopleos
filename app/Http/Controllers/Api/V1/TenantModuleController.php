<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantModule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateTenantModuleRequest;
use App\Http\Resources\TenantModuleResource;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\TenantModuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Checkpoint 47 — singleton-per-tenant endpoints, same "no {tenant} URL
 * parameter, always operates on app(Tenant::class)" shape as
 * TenantController. Platform Super Admin is blocked from ever reaching
 * this controller the same way they're blocked from every other tenant
 * route — tenant.modules.view/.manage are tenant-scoped permissions a
 * platform-admin session can never hold, and tenant.matches rejects
 * them even earlier (see EnsureModuleEnabled's docblock, and
 * TenantModuleApiTest for a direct test of that, not just an
 * assumption).
 */
class TenantModuleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $service = app(TenantModuleService::class);
        $enabledMap = $service->enabledMap();
        $warningCounts = $service->warningCounts();

        $items = array_map(
            fn (TenantModule $module) => [
                'module' => $module,
                'enabled' => $enabledMap[$module->value],
                'warning_count' => $warningCounts[$module->value] ?? null,
            ],
            TenantModule::toggleable(),
        );

        return TenantModuleResource::collection($items);
    }

    /**
     * $moduleKey is deliberately a plain string, not enum-bound —
     * unknown keys and core (non-toggleable) keys both get a clean 422
     * here, never a 404, per your explicit approved choice: this is
     * configuration management, not object lookup.
     */
    public function update(UpdateTenantModuleRequest $request, string $moduleKey): TenantModuleResource
    {
        $module = TenantModule::tryFrom($moduleKey);
        abort_if($module === null, 422, 'Unknown module key.');
        abort_unless($module->isToggleable(), 422, 'This module cannot be disabled.');

        $service = app(TenantModuleService::class);
        $previousEnabled = $service->isEnabled($module);
        $newEnabled = $request->boolean('enabled');

        $service->setEnabled($module, $newEnabled, $request->user());

        // Safe metadata only — module_key and the before/after booleans,
        // never a raw tenant_modules row.
        AuditLogger::logFor(
            actor: $request->user(),
            action: $newEnabled ? 'module.enabled' : 'module.disabled',
            module: 'settings',
            tenantId: app(Tenant::class)->id,
            description: "Module '{$module->label()}' ".($newEnabled ? 'enabled' : 'disabled').'.',
            metadata: ['module_key' => $module->value, 'previous_enabled' => $previousEnabled, 'new_enabled' => $newEnabled],
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new TenantModuleResource([
            'module' => $module,
            'enabled' => $newEnabled,
            'warning_count' => $service->warningCounts()[$module->value] ?? null,
        ]);
    }
}
