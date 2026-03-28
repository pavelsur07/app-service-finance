<?php

declare(strict_types=1);

namespace App\Cash\Enum\PaymentPlan;

enum PaymentPlanType: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
    case TRANSFER = 'TRANSFER';
}
