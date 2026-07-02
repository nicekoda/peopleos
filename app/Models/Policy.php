<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Policy extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'code',
        'description',
        'category',
        'owner_user_id',
        'status',
        'current_version_id',
        'effective_date',
        'review_date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PolicyStatus::class,
            'effective_date' => 'date',
            'review_date' => 'date',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'current_version_id');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
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
