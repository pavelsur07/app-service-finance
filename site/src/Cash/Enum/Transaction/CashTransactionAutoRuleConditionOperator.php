<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashTransactionAutoRuleConditionOperator: string
{
    case EQUAL = 'EQUAL';
    case GREATER_THAN = 'GREATER_THAN';
    case LESS_THAN = 'LESS_THAN';
    case BETWEEN = 'BETWEEN';
    case NOT_CONTAINS = 'NOT_CONTAINS';
    case CONTAINS = 'CONTAINS';
}
