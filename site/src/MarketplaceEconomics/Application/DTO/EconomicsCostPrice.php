<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

/**
 * Себестоимость проданного за период по витрине: продажи за вычетом возвратов × цена листинга на
 * день, со знаком затраты (≤ 0). `daysWithout` — дней кабинетов, где у части проданного нет цены.
 */
final readonly class EconomicsCostPrice
{
    public function __construct(
        public int $amountMinor,
        public int $daysWithout,
    ) {
    }
}
