<?php

namespace App\Models;

use App\Enums\CustomFieldDefinitionStatus;
use App\Enums\CustomFieldEntity;
use App\Enums\CustomFieldSensitivity;
use App\Enums\CustomFieldType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomFieldDefinition extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        // tenant_id is never accepted from *request* input, but must be
        // fillable for the service's trusted, explicit assignment to
        // actually persist — excluding it silently drops the value, the
        // same gap DocumentCategory documents having hit in Checkpoint 10.
        'tenant_id',
        'entity_type',
        'field_key',
        'label',
        'description',
        'field_type',
        'is_required',
        'default_value',
        'sensitivity',
        'sort_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => CustomFieldEntity::class,
            'field_type' => CustomFieldType::class,
            'is_required' => 'boolean',
            'sensitivity' => CustomFieldSensitivity::class,
            'sort_order' => 'integer',
            'status' => CustomFieldDefinitionStatus::class,
        ];
    }

    public function validationRules(): HasMany
    {
        return $this->hasMany(CustomFieldValidationRule::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(CustomFieldOption::class)->orderBy('sort_order');
    }

    /**
     * Checkpoint 53 — the configurable override layer on top of the
     * fixed sensitivity-tier model. Consulted exclusively through
     * CustomFieldAccessResolver::resolve(), never queried directly by
     * any other consumer (CustomFieldValueService, CustomFieldDefinitionResource,
     * CustomFormResource/CustomFormSectionResource) — one source of
     * truth, not a second one.
     */
    public function visibilityRules(): HasMany
    {
        return $this->hasMany(CustomFieldVisibilityRule::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
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
