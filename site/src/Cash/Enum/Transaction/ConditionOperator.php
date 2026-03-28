<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum ConditionOperator: string
{
    case EQUALS = 'equals';
    case CONTAINS = 'contains';
    case REGEX = 'regex';
    case BETWEEN = 'between';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case NOT_CONTAINS = 'not_contains';
    case NOT_EQUALS = 'not_equals';
}
