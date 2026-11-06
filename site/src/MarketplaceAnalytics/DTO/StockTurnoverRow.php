<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

use App\MarketplaceAnalytics\Enum\TurnoverBucket;

/** Строка отчёта «Оборачиваемость» — один листинг с ненулевым остатком на дату. */
final readonly class StockTurnoverRow
{
    public function __construct(
        public string $listingId,
        public ?string $title,
        public string $sku,
        public ?string $supplierSku,
        public string $marketplace,
        public int $stockQty,
        public int $salesQty,
        public float $salesPerDay,
        public ?int $daysOfCover,
        /** Себестоимость за единицу; null — не задана, строка не входит в деньги. */
        public ?string $costPrice,
        /** stockQty × costPrice, scale 2; null при отсутствии себестоимости. */
        public ?string $stockValue,
        public TurnoverBucket $bucket,
    ) {
    }
}
