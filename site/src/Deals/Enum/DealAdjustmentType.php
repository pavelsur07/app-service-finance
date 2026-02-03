<?php

namespace App\Deals\Enum;

enum DealAdjustmentType: string
{
    case RETURN = 'return';
    case DISCOUNT = 'discount';
    case CORRECTION = 'correction';
}
