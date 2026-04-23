<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Enum;

/**
 * Состояние батча в cron-driven pipeline обработки рекламных отчётов Ozon.
 *
 * State machine: PLANNED → IN_FLIGHT → (OK | FAILED | ABANDONED).
 * Терминальные состояния определяются через {@see self::isTerminal()} —
 * переход из них запрещён, финализатор job'а их только считает.
 */
enum AdScheduledBatchState: string
{
    case PLANNED = 'PLANNED';
    case IN_FLIGHT = 'IN_FLIGHT';
    case OK = 'OK';
    case FAILED = 'FAILED';
    case ABANDONED = 'ABANDONED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::OK, self::FAILED, self::ABANDONED], true);
    }
}
