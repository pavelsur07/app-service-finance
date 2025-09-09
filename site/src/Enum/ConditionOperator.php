<?php

namespace App\Enum;

enum ConditionOperator: string
{
    case EQUALS = 'equals';
    case CONTAINS = 'contains';
    case REGEX = 'regex';
    case BETWEEN = 'between';
    case IN = 'in';
    case NOT_CONTAINS = 'not_contains';
    case NOT_EQUALS = 'not_equals';
}
