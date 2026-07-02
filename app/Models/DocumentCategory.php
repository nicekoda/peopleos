<?php

namespace App\Models;

use App\Enums\DocumentAppliesTo;
use App\Enums\DocumentCategoryStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentCategory extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'applies_to',
        'is_sensitive',
        'is_required',
        'requires_expiry_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'applies_to' => DocumentAppliesTo::class,
            'status' => DocumentCategoryStatus::class,
            'is_sensitive' => 'boolean',
            'is_required' => 'boolean',
            'requires_expiry_date' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
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
