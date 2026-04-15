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
        public readonly string  $dateFrom,        // 'Y-m-d H:i:s', начало партии (00:00:00)
        public readonly string  $dateTo,          // 'Y-m-d H:i:s', конец партии (23:59:59)
        public readonly ?string $nextDateFrom,    // 'Y-m-d H:i:s' либо null если последняя партия
        public readonly ?string $nextDateTo,      // 'Y-m-d H:i:s' либо null если последняя партия
    ) {
    }
}
