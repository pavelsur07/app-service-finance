<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingReturnAggregateDTO
{
    public function __construct(
        public string $listingId,
        public string $returnsTotal,
        public int $returnsQuantity,
        /** Себестоимость возвращённых единиц (`cost_price × quantity`), как `return_cost_price` в ОПиУ. */
        public string $costPriceTotal = '0',
    ) {
    }
}
