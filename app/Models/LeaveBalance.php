<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveBalance extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * used_days/pending_days ARE fillable — set explicitly by
     * LeaveBalanceService, never by request input (structurally absent
     * from UpdateLeaveBalanceRequest's rules). Same "trusted controller/
     * service assignment, not request input" pattern used everywhere
     * else in this app.
     */
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'entitlement_days',
        'used_days',
        'pending_days',
        'carried_forward_days',
        'adjustment_days',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entitlement_days' => 'decimal:2',
            'used_days' => 'decimal:2',
            'pending_days' => 'decimal:2',
            'carried_forward_days' => 'decimal:2',
            'adjustment_days' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Deliberately not stored — computed on read to avoid a stale
     * cached value ever drifting from the fields it's derived from. See
     * docs/security.md.
     */
    public function availableDays(): float
    {
        return (float) $this->entitlement_days
            + (float) $this->carried_forward_days
            + (float) $this->adjustment_days
            - (float) $this->used_days
            - (float) $this->pending_days;
    }
}
