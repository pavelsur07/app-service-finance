<?php

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleOperationType: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
    case ANY = 'ANY';
}
