<?php

namespace App\Cash\Enum\Transaction;

enum CashDirection: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
}
