<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Domain;

/** Витрина одного дня кабинета и её контрольные итоги. */
final readonly class DayEconomics
{
    /**
     * @param list<EconomicsLine> $lines
     */
    public function __construct(
        public array $lines,
        /** Σ операций канона дня без справочных. */
        public int $canonMinor,
        /** Σ строк без SKU после разнесения — «нераспределено». */
        public int $unallocatedMinor,
        public int $costPriceMinor,
        /** Листингов с продажами или возвратами без себестоимости на дату. */
        public int $withoutCostPrice,
        public string $currency,
    ) {
    }
}
