<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

final readonly class StockTurnoverReport
{
    /**
     * @param list<StockTurnoverRow> $rows отсортированы по stockValue по убыванию, без себестоимости — в конце
     */
    public function __construct(
        public array $rows,
        public StockTurnoverSummary $summary,
    ) {
    }
}
