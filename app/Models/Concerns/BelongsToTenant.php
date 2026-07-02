<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies mandatory tenant scoping to a model.
 *
 * Every tenant-owned model must use this trait. It automatically:
 * - Filters all queries to the currently resolved tenant (when one is
 *   bound in the container, e.g. by ResolveTenant middleware).
 * - Fills tenant_id on creation from the currently resolved tenant, if
 *   not already explicitly set.
 *
 * Outside a resolved-tenant context (CLI, tests, seeders, platform-level
 * requests), no automatic scoping or filling occurs — callers are
 * responsible for setting tenant_id explicitly in those cases.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (app()->bound(Tenant::class)) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', app(Tenant::class)->id);
            }
        });

        static::creating(function ($model): void {
            if (! $model->tenant_id && app()->bound(Tenant::class)) {
                $model->tenant_id = app(Tenant::class)->id;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
