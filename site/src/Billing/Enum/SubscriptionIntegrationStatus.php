<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum SubscriptionIntegrationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
