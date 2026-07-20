<?php

namespace App\Models;

use App\Enums\CustomFieldVisibilityRuleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checkpoint 53 — an override layer on top of the fixed sensitivity-tier
 * model, never a replacement for it. No `tenant_id` column and no
 * BelongsToTenant — always reached through its owning
 * CustomFieldDefinition, the same posture CustomFieldOption/
 * CustomFormSection/CustomFormField already established for child rows
 * in this subsystem. Never hard-deleted — disabled via `status` only.
 */
class CustomFieldVisibilityRule extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'custom_field_definition_id',
        'role_id',
        'can_view',
        'can_edit',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'can_view' => 'boolean',
            'can_edit' => 'boolean',
            'status' => CustomFieldVisibilityRuleStatus::class,
        ];
    }

    public function customFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
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
