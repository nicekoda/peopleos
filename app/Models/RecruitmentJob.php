<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\RecruitmentJobStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitmentJob extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'department_id',
        'position_id',
        'location_id',
        'employment_type',
        'description',
        'status',
        'opened_at',
        'closed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => RecruitmentJobStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class);
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
