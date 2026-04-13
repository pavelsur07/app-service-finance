<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingSalesAggregateDTO
{
    public function __construct(
        public string $listingId,
        public ?string $title,
        public string $sku,
        public string $marketplace,
        public string $revenue,
        public int $quantity,
        public string $costPriceTotal,
        public int $costPriceQuantity,
    ) {}
}
