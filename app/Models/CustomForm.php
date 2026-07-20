<?php

namespace App\Models;

use App\Enums\CustomFieldEntity;
use App\Enums\CustomFormStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Checkpoint 52 — a tenant-owned grouping of existing custom fields into
 * sections for display/submission on one entity's own page. Never a
 * second value store: this row and its sections/fields only describe
 * layout — CustomFieldValueService remains the only place values are
 * read or written. entity_type reuses CustomFieldEntity directly (no
 * parallel CustomFormEntity — see docs/architecture.md).
 */
class CustomForm extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        // tenant_id is never accepted from *request* input, but must be
        // fillable for the service's trusted, explicit assignment to
        // actually persist — same reasoning as CustomFieldDefinition's
        // own tenant_id.
        'tenant_id',
        'entity_type',
        'form_key',
        'name',
        'description',
        'status',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entity_type' => CustomFieldEntity::class,
            'status' => CustomFormStatus::class,
            'sort_order' => 'integer',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CustomFormSection::class)->orderBy('sort_order');
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
