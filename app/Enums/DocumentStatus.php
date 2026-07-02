<?php

namespace App\Enums;

/**
 * "deleted" is deliberately not a value here — soft delete (deleted_at)
 * is the actual delete mechanism (per the "soft delete preferred" rule),
 * so a separate status=deleted value would let a row be both "active"
 * and deleted simultaneously. See docs/database.md.
 */
enum DocumentStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Rejected = 'rejected';
}
