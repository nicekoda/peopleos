<?php

namespace App\Models;

use App\Enums\CustomFormStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * No `tenant_id` column and no BelongsToTenant — always reached through
 * its owning CustomForm, never queried independently (same posture as
 * CustomFieldOption relative to CustomFieldDefinition).
 */
class CustomFormSection extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'custom_form_id',
        'section_key',
        'title',
        'description',
        'sort_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomFormStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(CustomForm::class, 'custom_form_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(CustomFormField::class)->orderBy('sort_order');
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
