<?php

declare(strict_types=1);

namespace App\Billing\Enum;

enum BillingPeriod: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
