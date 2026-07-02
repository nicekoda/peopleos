<?php

namespace App\Models;

use App\Enums\AcknowledgementMethod;
use App\Enums\AcknowledgementStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No soft deletes — not in the original field list, and there's no
 * delete endpoint. These are compliance-evidence records.
 */
class PolicyAcknowledgement extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'policy_id',
        'policy_version_id',
        'employee_id',
        'assigned_by',
        'assigned_at',
        'due_date',
        'acknowledged_at',
        'acknowledgement_status',
        'acknowledgement_method',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'acknowledgement_status' => AcknowledgementStatus::class,
            'acknowledgement_method' => AcknowledgementMethod::class,
            'assigned_at' => 'datetime',
            'due_date' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
