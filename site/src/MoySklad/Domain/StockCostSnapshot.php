<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

/** Себестоимость единицы ассортимента из отчёта «Остатки» МойСклад, в копейках (может быть дробной). */
final readonly class StockCostSnapshot
{
    public function __construct(
        public string $assortmentType,
        public string $assortmentExternalId,
        public string $priceMinor,
    ) {
    }
}
