<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum SubscriptionStatus: string
{
    case TRIAL = 'TRIAL';
    case ACTIVE = 'ACTIVE';
    case GRACE = 'GRACE';
    case SUSPENDED = 'SUSPENDED';
    case CANCELED = 'CANCELED';
}
