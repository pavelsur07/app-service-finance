<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

/**
 * Сводка отчёта по всем строкам фильтра (источник, схема, теги, дата) без учёта
 * фильтра корзины. Листинги без себестоимости в деньги не входят и считаются отдельно.
 */
final readonly class StockTurnoverSummary
{
    /**
     * @param array<string, string> $valueByBucket TurnoverBucket value → ₽ (все пять корзин)
     * @param array<string, string> $snapshotDateBySource marketplace → дата снимка остатков
     * @param array<string, string> $staleSources marketplace → дата последнего снимка, отброшенного как устаревший
     */
    public function __construct(
        public string $totalValue,
        public string $frozenValue,
        /** Доля замороженного в процентах; null, когда денег в товаре нет. */
        public ?float $frozenShare,
        public array $valueByBucket,
        public int $listingsWithoutCostPrice,
        public int $qtyWithoutCostPrice,
        public int $listingsTotal,
        public array $snapshotDateBySource,
        public array $staleSources,
    ) {
    }
}
