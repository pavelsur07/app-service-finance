<?php

declare(strict_types=1);

namespace App\Deals\Enum;

enum DealAdjustmentType: string
{
    case RETURN = 'return';
    case DISCOUNT = 'discount';
    case CORRECTION = 'correction';
}
