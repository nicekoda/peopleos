<?php

namespace App\Models;

use App\Enums\CustomFieldDefinitionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No `tenant_id` column and no BelongsToTenant — always reached through
 * its owning CustomFieldDefinition, never queried independently.
 */
class CustomFieldOption extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'custom_field_definition_id',
        'option_key',
        'label',
        'sort_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'status' => CustomFieldDefinitionStatus::class,
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
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
