<?php

namespace App\Enums;

/**
 * Checkpoint 48. Affects audit masking only in this checkpoint — does
 * NOT yet create field-level read permissions (your explicit decision,
 * documented so it's never mistaken for real access control). A future
 * checkpoint may add read-time filtering keyed on this classification.
 */
enum CustomFieldSensitivity: string
{
    case Normal = 'normal';
    case Sensitive = 'sensitive';
    case Confidential = 'confidential';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Sensitive => 'Sensitive',
            self::Confidential => 'Confidential',
            self::Restricted => 'Restricted',
        };
    }

    /**
     * Whether a value under this classification must be masked in audit
     * log old_values/new_values — everything except Normal.
     */
    public function requiresAuditMasking(): bool
    {
        return $this !== self::Normal;
    }
}
