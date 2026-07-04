<?php

namespace App\Services\Audit;

/**
 * Response-level sanitisation (Checkpoint 24) — applied to
 * metadata/old_values/new_values inside AuditLogResource, regardless of
 * whatever masking already happened at write time in AuditLogger. This
 * matters most for `metadata`, which AuditLogger deliberately never
 * masks at write time (it was designed for small, presumed-safe
 * contextual tags) — this is the first real protection metadata gets.
 * For old_values/new_values, this is intentionally redundant with
 * AuditLogger's own write-time masking — genuine defense in depth, not
 * a replacement for it.
 *
 * Uses a fuller, more conservative pattern list than AuditLogger's own
 * (which only needed to cover employee/leave/document fields seen so
 * far) — deliberately broad enough to accept some false positives
 * (e.g. a `permission_key` value gets masked because it contains
 * "key") in exchange for not needing to enumerate every safe field
 * name. Prefer over-masking to under-masking here.
 */
class AuditValueSanitizer
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password',
        'token',
        'secret',
        'key',
        'authorization',
        'cookie',
        'session',
        'remember',
        'reset',
        'bank',
        'iban',
        'salary',
        'medical',
        'reason',
        'rejection_reason',
        'storage_path',
        'stored_filename',
        'file_path',
        'private_path',
        'ip_address',
        'user_agent',
    ];

    private const MASK = '***MASKED***';

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function sanitize(?array $values): ?array
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
