<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only. No updated_at, no soft deletes — a log entry is written
 * once and never edited. save() on an existing row and delete() both
 * throw, so this is enforced at the model layer, not just by the absence
 * of an edit/delete UI (there isn't one yet, but this holds regardless of
 * what future code touches this model).
 *
 * Deliberately does not use BelongsToTenant: audit events happen in
 * contexts (login, CLI, seeders) where an ambient bound tenant would be
 * unreliable. AuditLogger::log() always takes an explicit tenant_id.
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'actor_type',
        'action',
        'module',
        'auditable_type',
        'auditable_id',
        'target_user_id',
        'description',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'severity',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ];
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new RuntimeException('Audit logs are append-only and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new RuntimeException('Audit logs cannot be deleted.');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
