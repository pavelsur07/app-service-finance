<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Application\DTO;

/** День витрины кабинета: Σ строк (без себестоимости и справочных), нераспределённое и затраты. */
final readonly class EconomicsDaySum
{
    public function __construct(
        public string $connectionRef,
        public string $date,
        public int $linesMinor,
        public int $unallocatedMinor,
        public int $costsMinor,
    ) {
    }
}
