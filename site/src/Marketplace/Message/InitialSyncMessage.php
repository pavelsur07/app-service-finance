<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Одна недельная партия первичной загрузки (Пн–Вс).
 * После успешной загрузки Handler диспатчит следующую партию.
 */
final class InitialSyncMessage
{
    public function __construct(
        public readonly string  $companyId,
        public readonly string  $connectionId,
        public readonly string  $marketplace,    // MarketplaceType::value
        public readonly string  $dateFrom,        // Y-m-d, всегда Пн
        public readonly string  $dateTo,          // Y-m-d, Вс или сегодня для последней партии
        public readonly ?string $nextDateFrom,    // null если последняя партия
        public readonly ?string $nextDateTo,      // null если последняя партия
    ) {
    }
}
