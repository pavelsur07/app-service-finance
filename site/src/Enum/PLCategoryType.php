<?php

namespace App\Enum;

enum PLCategoryType: string
{
    case LEAF_INPUT = 'LEAF_INPUT';
    case SUBTOTAL = 'SUBTOTAL';
    case KPI = 'KPI';
}
