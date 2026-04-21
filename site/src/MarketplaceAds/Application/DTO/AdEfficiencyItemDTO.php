<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

final readonly class AdEfficiencyItemDTO
{
    public function __construct(
        public string $listingId,
        public string $sku,
        public ?string $title,
        public string $marketplace,
        public string $revenue,
        public string $adSpend,
        public ?string $drrPercent,
    ) {
    }
}
