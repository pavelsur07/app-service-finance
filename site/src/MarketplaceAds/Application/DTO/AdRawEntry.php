<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

final readonly class AdRawEntry
{
    public function __construct(
        public string $campaignId,
        public string $campaignName,
        public string $parentSku,
        public string $cost,
        public int $impressions,
        public int $clicks,
    ) {}
}
