<?php

declare(strict_types=1);

namespace App\Budget\Enum;

/**
 * Откуда взята сумма строки плана. `sales_plan` — снимок из модуля плана продаж (после MVP).
 */
enum BudgetLineSource: string
{
    case MANUAL = 'manual';
    case SALES_PLAN = 'sales_plan';
}
