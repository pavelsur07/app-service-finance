<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleAction: string
{
    case FILL = 'FILL';
    case UPDATE = 'UPDATE';
}
