<?php

namespace App\Enums;

/**
 * "web" is reserved for a future genuine self-service flow, once a
 * verified user-to-employee link exists. Every acknowledgement created
 * this checkpoint is admin_recorded — see docs/security.md.
 */
enum AcknowledgementMethod: string
{
    case Web = 'web';
    case AdminRecorded = 'admin_recorded';
}
