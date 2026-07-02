<?php

namespace App\Enums;

enum DocumentAppliesTo: string
{
    case Employee = 'employee';
    case Tenant = 'tenant';
    case Policy = 'policy';
    case Candidate = 'candidate';
    case General = 'general';
}
