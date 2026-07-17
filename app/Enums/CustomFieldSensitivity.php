<?php

namespace App\Enums;

/**
 * Checkpoint 48 added this classification for audit masking only.
 * Checkpoint 50 gives it real read/write consequences via
 * requiredAccessPermission() — the single source of truth mapping a
 * tier to the permission required to view/edit it, reused by both
 * CustomFieldValueService (enforcement) and CustomFieldDefinitionResource
 * (the can_view/can_edit metadata the frontend renders from). Fixed,
 * platform-defined permissions for MVP — no tenant-configurable
 * visibility-rules table yet (see docs/architecture.md for why, and
 * the future-compatibility note on how an override layer could be
 * added later without redesigning this).
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
     * log old_values/new_values — everything except Normal. Applies
     * regardless of the actor's own field-level access (Checkpoint 50
     * decision 13) — an audit log may later be read by a different,
     * less-privileged auditor, so masking is never conditional on who
     * performed the original action.
     */
    public function requiresAuditMasking(): bool
    {
        return $this !== self::Normal;
    }

    /**
     * The permission required, in addition to whatever parent-entity
     * permission already gates the read/write action, to view or edit
     * a field at this tier — null for Normal, meaning no additional
     * permission is required beyond the parent's (unchanged from
     * Checkpoint 48/49 behavior). Deliberately no implied hierarchy —
     * `access_restricted` does not imply `access_confidential`/
     * `access_sensitive` (Checkpoint 50, decision 5); each tier is a
     * fully independent grant, matching how no other permission pair
     * in this app implies another.
     */
    public function requiredAccessPermission(): ?string
    {
        return match ($this) {
            self::Normal => null,
            self::Sensitive => 'custom_fields.access_sensitive',
            self::Confidential => 'custom_fields.access_confidential',
            self::Restricted => 'custom_fields.access_restricted',
        };
    }
}
