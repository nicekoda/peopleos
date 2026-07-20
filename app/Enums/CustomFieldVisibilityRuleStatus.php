<?php

namespace App\Enums;

/**
 * Checkpoint 53 — mirrors CustomFieldDefinitionStatus/CustomFormStatus
 * exactly (Active|Inactive). A separate enum per domain object rather
 * than reusing one of the others, matching this app's established
 * convention (each domain gets its own status vocabulary even when the
 * values are identical) — see CustomFormStatus's own docblock.
 */
enum CustomFieldVisibilityRuleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
