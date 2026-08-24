<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum IntegrationBillingType: string
{
    case INCLUDED = 'INCLUDED';
    case ADDON = 'ADDON';
}
