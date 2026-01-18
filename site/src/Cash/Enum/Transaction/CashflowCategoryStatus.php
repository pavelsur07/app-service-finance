<?php

namespace App\Cash\Enum\Transaction;

enum CashflowCategoryStatus: string
{
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}
