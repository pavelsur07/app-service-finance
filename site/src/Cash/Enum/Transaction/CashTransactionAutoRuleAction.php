<?php

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleAction: string
{
    case FILL = 'FILL';
    case UPDATE = 'UPDATE';
}
