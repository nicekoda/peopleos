<?php

namespace App\Models;

use App\Enums\LifecycleTaskStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LifecycleTask extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * Eloquent's default inferred table name would be "lifecycle_tasks"
     * — the actual migration uses "employee_lifecycle_tasks", so this
     * must be explicit.
     */
    protected $table = 'employee_lifecycle_tasks';

    /**
     * process_id is fillable but never accepted from *request* input —
     * StoreLifecycleTaskRequest has no process_id field, the controller
     * always resolves it from the route-bound process. title/
     * description/assigned_to_user_id/due_date/status are the safe,
     * request-validated fields; completed_at/completed_by/created_by/
     * updated_by are always controller-set from trusted context.
     */
    protected $fillable = [
        'process_id',
        'title',
        'description',
        'assigned_to_user_id',
        'status',
        'due_date',
        'sort_order',
        'completed_at',
        'completed_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LifecycleTaskStatus::class,
            'due_date' => 'date',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(LifecycleProcess::class, 'process_id');
    }

    public function assignedToUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
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
