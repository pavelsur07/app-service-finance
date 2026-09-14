<?php

declare(strict_types=1);

namespace App\Balance\Enum;

enum BalanceDirection: string
{
    case INCREASE = 'increase';
    case DECREASE = 'decrease';
}
