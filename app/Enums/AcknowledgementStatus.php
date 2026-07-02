<?php

namespace App\Enums;

enum AcknowledgementStatus: string
{
    case Pending = 'pending';
    case Acknowledged = 'acknowledged';
    case Overdue = 'overdue';
    case Waived = 'waived';
}
