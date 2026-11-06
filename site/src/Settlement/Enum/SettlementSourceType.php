<?php

declare(strict_types=1);

namespace App\Settlement\Enum;

enum SettlementSourceType: string
{
    case MANUAL = 'manual';
    case CASH = 'cash';
    case FINANCE = 'finance';
}
