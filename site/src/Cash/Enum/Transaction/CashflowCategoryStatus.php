<?php

declare(strict_types=1);

namespace App\Cash\Enum\Transaction;

enum CashflowCategoryStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}
