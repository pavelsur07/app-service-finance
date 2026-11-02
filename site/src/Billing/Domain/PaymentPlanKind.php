<?php

declare(strict_types=1);

namespace App\Billing\Domain;

/**
 * Что делает оплата с подпиской.
 */
enum PaymentPlanKind: string
{
    /** Подписки нет, она истекла или бессрочная: период с дня зачисления. */
    case NEW_PERIOD = 'new_period';
    /** Тот же тариф (или пробный период): продолжение со дня после «оплачено по». */
    case RENEWAL = 'renewal';
    /** Дороже текущего при действующем периоде: сразу, остаток — днями нового тарифа. */
    case UPGRADE = 'upgrade';
    /** Дешевле текущего при действующем периоде: со дня после «оплачено по». */
    case DOWNGRADE = 'downgrade';
}
