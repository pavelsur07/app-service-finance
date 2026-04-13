<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingCostCategoryAggregateDTO
{
    public function __construct(
        public string $listingId,
        public string $categoryCode,
        public string $categoryName,
        public string $netAmount,
        public string $costsAmount,
        public string $stornoAmount,
    ) {}
}
