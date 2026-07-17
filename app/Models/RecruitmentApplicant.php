<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitmentApplicant extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'source',
        'created_by',
        'updated_by',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(RecruitmentApplication::class);
    }

    /**
     * Checkpoint 48 — custom field values for this applicant. No
     * Eloquent `morphTo`; scoped manually via entity_type/entity_id
     * (see CustomFieldValue's own docblock) since this table is shared
     * across every CustomFieldEntity case, not owned by this model alone.
     */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', CustomFieldEntity::RecruitmentApplicant->value);
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
