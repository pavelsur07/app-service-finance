<?php

namespace App\Enum;

enum PaymentPlanType: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
    case TRANSFER = 'TRANSFER';
}
