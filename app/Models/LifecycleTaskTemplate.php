<?php

namespace App\Models;

use App\Enums\LifecycleProcessType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Checkpoint 42 — reuses LifecycleProcessType (onboarding/offboarding)
 * rather than a new enum; a template's `type` determines which
 * LifecycleProcess creations it gets copied into. tenant_id/created_by/
 * updated_by are fillable for the same "controller's trusted, explicit
 * assignment" reason every other tenant-owned lookup model in this app
 * already documents (see Department).
 */
class LifecycleTaskTemplate extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'type',
        'title',
        'description',
        'due_in_days',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => LifecycleProcessType::class,
            'due_in_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
