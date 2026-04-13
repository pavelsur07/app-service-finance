<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

final readonly class CostDistributionResult
{
    public function __construct(
        public string $listingId,
        public string $sharePercent,
        public string $cost,
        public int $impressions,
        public int $clicks,
    ) {
    }
}
