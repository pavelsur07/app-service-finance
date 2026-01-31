<?php

namespace App\Billing\Enum;

enum BillingPeriod: string
{
    case MONTH = 'MONTH';
    case YEAR = 'YEAR';
}
