<?php

namespace App\Models;

use App\Enums\CustomFormStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No `tenant_id` column and no BelongsToTenant — always reached through
 * its owning CustomFormSection, never queried independently. Never a
 * second field definition: custom_field_definition_id always points at
 * a real, already-tenant/entity-validated CustomFieldDefinition row
 * (enforced in CustomFormDefinitionService, not here). is_required_override
 * is UI-only for Checkpoint 52 — CustomFieldValueValidator has no
 * knowledge of forms and never will in this checkpoint; see
 * docs/architecture.md for why.
 */
class CustomFormField extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'custom_form_section_id',
        'custom_field_definition_id',
        'label_override',
        'help_text',
        'placeholder',
        'is_required_override',
        'sort_order',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_required_override' => 'boolean',
            'sort_order' => 'integer',
            'status' => CustomFormStatus::class,
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CustomFormSection::class, 'custom_form_section_id');
    }

    public function customFieldDefinition(): BelongsTo
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
