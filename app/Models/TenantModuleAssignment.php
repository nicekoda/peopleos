<?php

namespace App\Models;

use App\Enums\TenantModule;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicit row per tenant per toggleable module (Checkpoint 47) —
 * the intended steady state, per your approved design. A missing row
 * is only a fallback (treated as enabled by TenantModuleService), never
 * the expected case once backfill/provisioning has run — see
 * TenantModuleService::isEnabled().
 */
class TenantModuleAssignment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'tenant_modules';

    /**
     * tenant_id stays fillable (unlike Employee's stricter exclusion) —
     * TenantModuleService::provisionDefaults() creates these rows from
     * Tenant's own `created` hook, which can run outside a real HTTP
     * request (e.g. TenantSeeder), where no Tenant is bound in the
     * container — BelongsToTenant's creating-event auto-fill never
     * fires there, so tenant_id must be mass-assignable for the
     * explicit value to persist. Same reasoning as Department's
     * $fillable — see docs/architecture.md's CLI/tinker gotcha note.
     */
    protected $fillable = [
        'tenant_id',
        'module_key',
        'enabled',
        'enabled_by',
        'enabled_at',
        'disabled_by',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'module_key' => TenantModule::class,
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }
}
