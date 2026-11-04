<?php

declare(strict_types=1);

namespace App\Budget\Enum;

/**
 * Откуда взята сумма строки плана. `sales_plan` — снимок из модуля плана продаж (после MVP);
 * `rule` — считается правилом «X % от строки-базы» (BudgetRule), вручную не правится.
 */
enum BudgetLineSource: string
{
    case MANUAL = 'manual';
    case SALES_PLAN = 'sales_plan';
    case RULE = 'rule';
}
