<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashDirection: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
}
