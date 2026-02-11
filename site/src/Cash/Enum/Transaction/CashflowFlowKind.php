<?php

namespace App\Cash\Enum\Transaction;

enum CashflowFlowKind: string
{
    case OPERATING = 'OPERATING';
    case INVESTING = 'INVESTING';
    case FINANCING = 'FINANCING';
}
