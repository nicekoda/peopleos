<?php

namespace App\Models;

use App\Enums\CustomFieldValidationRuleKey;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * No `tenant_id` column and no BelongsToTenant on this table — it's
 * always reached through its owning CustomFieldDefinition (which is
 * itself tenant-scoped), never queried independently. Same posture as
 * CustomFieldOption below.
 */
class CustomFieldValidationRule extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'custom_field_definition_id',
        'rule_key',
        'rule_value',
    ];

    protected function casts(): array
    {
        return [
            'rule_key' => CustomFieldValidationRuleKey::class,
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
    }
}
