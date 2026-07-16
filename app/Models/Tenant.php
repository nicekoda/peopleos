<?php

namespace App\Models;

use App\Services\TenantModuleService;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'name',
        'subdomain',
        'status',
    ];

    /**
     * Checkpoint 47 — every new tenant (TenantSeeder today, any real
     * provisioning flow tomorrow) gets an explicit enabled row for every
     * toggleable module immediately, so "missing row" stays a fallback
     * case rather than the normal one going forward. Pre-existing
     * tenants are backfilled once, directly, by the tenant_modules
     * migration itself — this hook only covers tenants created *after*
     * that table exists.
     */
    protected static function booted(): void
    {
        static::created(function (self $tenant): void {
            app(TenantModuleService::class)->provisionDefaults($tenant);
        });
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
