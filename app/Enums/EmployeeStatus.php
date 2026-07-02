<?php

namespace App\Enums;

enum EmployeeStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Terminated = 'terminated';
}
