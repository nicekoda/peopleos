<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately no `entity()` Eloquent relation (no implicit polymorphic
 * `morphTo`) — entity_type/entity_id resolution always goes through
 * CustomFieldValueService, so an invalid or renamed entity_type can never
 * silently resolve to the wrong model via Eloquent's morph map.
 */
class CustomFieldValue extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'entity_type',
        'entity_id',
        'custom_field_definition_id',
        'value_text',
        'value_number',
        'value_date',
        'value_boolean',
        'value_json',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => CustomFieldEntity::class,
            'value_number' => 'float',
            'value_date' => 'date',
            'value_boolean' => 'boolean',
            'value_json' => 'array',
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
