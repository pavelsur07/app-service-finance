<?php

namespace App\Enum;

enum CashTransactionAutoRuleAction: string
{
    case FILL = 'FILL';
    case UPDATE = 'UPDATE';
}
