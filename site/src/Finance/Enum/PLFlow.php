<?php

declare(strict_types=1);

namespace App\Finance\Enum;

enum PLFlow: string
{
    case INCOME = 'INCOME';
    case EXPENSE = 'EXPENSE';
    case NONE = 'NONE';
}
