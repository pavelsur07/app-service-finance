<?php

declare(strict_types=1);

namespace App\Budget\Enum;

/**
 * Помощник заполнения плана (ui-ux.md, экран 4).
 */
enum BudgetFillHelper: string
{
    case LAST_YEAR_FACT = 'last_year_fact';
    case AVERAGE = 'average';
    case DISTRIBUTE = 'distribute';
    case COPY_RIGHT = 'copy_right';
    case PERCENT_OF = 'percent_of';
}
