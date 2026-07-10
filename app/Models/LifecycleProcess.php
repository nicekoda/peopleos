<?php

namespace App\Models;

use App\Enums\LifecycleProcessStatus;
use App\Enums\LifecycleProcessType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LifecycleProcess extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Eloquent's default inferred table name would be
     * "lifecycle_processes" (from the class name alone) — the actual
     * migration uses "employee_lifecycle_processes", so this must be
     * explicit.
     */
    protected $table = 'employee_lifecycle_processes';

    /**
     * employee_id, type, started_at, due_date are the only fields
     * StoreLifecycleProcessRequest actually validates — status/
     * completed_at/created_by/updated_by are always set by the
     * controller from trusted context, never request input, but must
     * stay fillable for that assignment to persist (same rule as every
     * other tenant-owned model — see Employee's $fillable comment).
     */
    protected $fillable = [
        'employee_id',
        'type',
        'status',
        'started_at',
        'due_date',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => LifecycleProcessType::class,
            'status' => LifecycleProcessStatus::class,
            'started_at' => 'datetime',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Checkpoint 45 — ordered by sort_order (then created_at as a stable
     * tiebreaker for tasks that share the default 0, e.g. several
     * template-derived tasks whose templates never set an explicit
     * order). This is the single place the ordering is applied, so
     * every caller (show(), store()'s ->load('tasks'), the reorder
     * endpoint's response) sees the same order without repeating an
     * ->orderBy() at each call site.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(LifecycleTask::class, 'process_id')->orderBy('sort_order')->orderBy('created_at');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
