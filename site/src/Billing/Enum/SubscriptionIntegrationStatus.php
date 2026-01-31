<?php

namespace App\Billing\Enum;

enum SubscriptionIntegrationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';
}
