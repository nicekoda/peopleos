<?php

namespace App\Models;

use App\Enums\LeaveTypeStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * created_by/updated_by are never accepted from *request* input, but
     * must be fillable for the controller's trusted, explicit assignment
     * to actually persist — see the Checkpoint 10 note on
     * App\Models\Employee for the bug class this avoids.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_paid',
        'requires_approval',
        'requires_document',
        'max_days_per_year',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeaveTypeStatus::class,
            'is_paid' => 'boolean',
            'requires_approval' => 'boolean',
            'requires_document' => 'boolean',
        ];
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
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
