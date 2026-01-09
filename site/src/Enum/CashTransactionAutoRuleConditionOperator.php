<?php

namespace App\Enum;

enum CashTransactionAutoRuleConditionOperator: string
{
    case EQUAL = 'EQUAL';
    case GREATER_THAN = 'GREATER_THAN';
    case LESS_THAN = 'LESS_THAN';
    case BETWEEN = 'BETWEEN';
    case CONTAINS = 'CONTAINS';
}
