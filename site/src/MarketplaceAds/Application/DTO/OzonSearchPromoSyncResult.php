<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

/**
 * Итог загрузки оплаты за заказ Ozon за период: дней записано (включая очищенные), строк отчёта,
 * расход, строк с заказанным SKU ≠ продвигаемому, нечитаемых строк сырья, дней с SKU без листинга.
 */
final readonly class OzonSearchPromoSyncResult
{
    public function __construct(
        public int $days,
        public int $rows,
        public string $total,
        public int $mismatchedSku,
        public int $unreadable,
        public int $daysWithUnmappedSku,
    ) {
    }
}
