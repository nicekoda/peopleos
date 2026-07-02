<?php

namespace App\Enums;

/**
 * Shared by both policies.status and policy_versions.status — the values
 * are identical for both, no reason for two separate enums.
 */
enum PolicyStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
