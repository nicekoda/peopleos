<?php

namespace App\Enums;

enum LifecycleProcessType: string
{
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';
}
