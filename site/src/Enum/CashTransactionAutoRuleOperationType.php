<?php

namespace App\Enum;

enum CashTransactionAutoRuleOperationType: string
{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';
    case ANY = 'ANY';
}
