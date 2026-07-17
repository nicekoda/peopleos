<?php

namespace App\Enums;

/**
 * Mirrors DocumentCategoryStatus exactly (Active|Inactive) — "disabled"
 * in this checkpoint's audit-event/UI language means this enum's
 * Inactive case, the same "enable/disable" vocabulary TenantModule's
 * boolean `enabled` column uses for its own audit events.
 */
enum CustomFieldDefinitionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
