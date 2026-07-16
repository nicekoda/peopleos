<?php

namespace App\Http\Resources;

use App\Enums\TenantModule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a plain array, not an Eloquent model — the resource shown here
 * is a synthesis of the TenantModule registry (label/description/
 * toggleable/related) plus the per-tenant enabled state and an
 * optional safe warning count, never a raw tenant_modules row. Never
 * exposes row IDs, actor IDs, or timestamps (Checkpoint 47) — even for
 * a caller with tenant.modules.view only, not .manage.
 *
 * Expects: ['module' => TenantModule, 'enabled' => bool, 'warning_count' => int|null]
 */
class TenantModuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TenantModule $module */
        $module = $this->resource['module'];

        return [
            'module_key' => $module->value,
            'label' => $module->label(),
            'description' => $module->description(),
            'enabled' => $this->resource['enabled'],
            'toggleable' => $module->isToggleable(),
            'related_modules' => array_map(fn (TenantModule $related) => $related->value, $module->relatedModules()),
            'warning_count' => $this->resource['warning_count'] ?? null,
        ];
    }
}
