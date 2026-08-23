<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

use App\Marketplace\Enum\MarketplaceType;

final class RecalculateSalesCostPriceCommand
{
    /**
     * @param list<string> $listingIds empty list recalculates all listings
     */
    public function __construct(
        public readonly string $companyId,
        public readonly MarketplaceType $marketplace,
        public readonly \DateTimeImmutable $dateFrom,
        public readonly \DateTimeImmutable $dateTo,
        public readonly bool $onlyZeroCost = false,
        public readonly array $listingIds = [],
    ) {
    }
}
