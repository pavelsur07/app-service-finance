<?php

namespace App\Billing\Enum;

enum SubscriptionStatus: string
{
    case TRIAL = 'TRIAL';
    case ACTIVE = 'ACTIVE';
    case GRACE = 'GRACE';
    case SUSPENDED = 'SUSPENDED';
    case CANCELED = 'CANCELED';
}
