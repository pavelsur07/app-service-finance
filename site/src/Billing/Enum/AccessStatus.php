<?php

declare(strict_types=1);

namespace App\Billing\Enum;

/**
 * Статус доступа компании на дату; выводится из дат подписки, не хранится.
 */
enum AccessStatus: string
{
    /** Период оплачен или тариф бессрочный. */
    case ACTIVE = 'active';
    /** Период истёк, идут льготные дни: работает всё, показывается напоминание. */
    case GRACE = 'grace';
    /** Льготные дни прошли: данные видны и выгружаются, изменять нельзя. */
    case READ_ONLY = 'read_only';
    /** Подписки у компании нет. */
    case NONE = 'none';
}
