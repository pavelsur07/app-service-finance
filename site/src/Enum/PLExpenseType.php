<?php

declare(strict_types=1);

namespace App\Enum;

enum PLExpenseType: string
{
    case VARIABLE = 'variable';
    case OPEX = 'opex';
    case OTHER = 'other';
}
