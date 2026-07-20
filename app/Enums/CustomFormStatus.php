<?php

namespace App\Enums;

/**
 * Checkpoint 52 — mirrors CustomFieldDefinitionStatus exactly
 * (Active|Inactive), reused for custom_forms/custom_form_sections/
 * custom_form_fields alike, all three sharing one status vocabulary
 * rather than three near-identical enums. No `draft` state in MVP —
 * nothing here has an approval/staging workflow yet; a future
 * checkpoint could add one as a new case without redesigning anything,
 * the same way Checkpoint 50 added tier permissions on top of
 * Checkpoint 48's sensitivity enum without touching its shape.
 */
enum CustomFormStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
