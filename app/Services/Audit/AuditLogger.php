<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Reusable audit logging entry point for the whole application.
 *
 * Usage: AuditLogger::log(action: 'login.success', module: 'auth', ...)
 *
 * tenant_id is always explicit — never inferred from an ambient bound
 * Tenant — since this is called from contexts (login, CLI, seeders) where
 * that would be unreliable. Pass null explicitly for platform-level
 * events.
 */
class AuditLogger
{
    /**
     * Key names/substrings that are masked wherever they appear in
     * old_values/new_values, regardless of whether the caller remembered
     * to exclude them. Matched case-insensitively, by substring, so
     * variants (bank_account_number, national_id_number, ...) are caught
     * without needing to enumerate every possible field name.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'token',
        'secret',
        'bank',
        'account_number',
        'national_id',
        'passport',
        'salary',
        'ssn',
        'tax_id',
    ];

    private const MASK = '***MASKED***';

    public static function log(
        string $action,
        string $module,
        ?int $actorUserId = null,
        ?string $actorType = null,
        ?string $tenantId = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?int $targetUserId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        string $severity = 'info',
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorType ?? ($actorUserId ? 'user' : 'system'),
            'action' => $action,
            'module' => $module,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'target_user_id' => $targetUserId,
            'description' => $description,
            'old_values' => self::mask($oldValues),
            'new_values' => self::mask($newValues),
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'severity' => $severity,
        ]);
    }

    /**
     * Convenience wrapper for logging an action performed by (or on
     * behalf of) a given user, populating actorUserId/actorType/tenantId
     * from that user automatically.
     */
    public static function logFor(
        ?User $actor,
        string $action,
        string $module,
        ?string $tenantId = null,
        ?string $auditableType = null,
        ?string $auditableId = null,
        ?int $targetUserId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        string $severity = 'info',
    ): AuditLog {
        return self::log(
            action: $action,
            module: $module,
            actorUserId: $actor?->id,
            tenantId: $tenantId ?? $actor?->tenant_id,
            auditableType: $auditableType,
            auditableId: $auditableId,
            targetUserId: $targetUserId,
            description: $description,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: $metadata,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            severity: $severity,
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private static function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
                if (str_contains($normalizedKey, $pattern)) {
                    $values[$key] = self::MASK;

                    break;
                }
            }
        }

        return $values;
    }
}
