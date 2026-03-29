<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Domain\ValueObject\DataQualityFlags;

final readonly class ListingUnitEconomics
{
    public function __construct(
        public string $listingId,
        public ?string $listingName,
        public string $marketplaceSku,
        public string $marketplaceType,
        public AnalysisPeriod $period,
        public string $revenue,
        public string $refunds,
        public string $avgSalePrice,
        public ?string $costPrice,
        public string $logisticsTo,
        public string $logisticsBack,
        public string $storage,
        public string $advertisingCpc,
        public string $advertisingOther,
        public string $advertisingExternal,
        public string $commission,
        public string $otherCosts,
        public int $advertisingImpressions,
        public int $advertisingClicks,
        public ?float $advertisingCtr,
        public ?float $advertisingCr,
        public ?string $advertisingCpo,
        public ?string $advertisingAcos,
        public ?float $purchaseRate,
        public ?string $profitPerUnit,
        public ?string $profitTotal,
        public ?float $drr,
        public ?float $roi,
        public ?float $ros,
        public ?string $gmroi,
        public int $salesQuantity,
        public int $returnsQuantity,
        public int $ordersQuantity,
        public int $deliveredQuantity,
        public DataQualityFlags $dataQuality,
    ) {}

    public function hasCostPrice(): bool
    {
        return $this->costPrice !== null;
    }

    public function isComplete(): bool
    {
        return $this->dataQuality->isComplete();
    }

    public function isProfitable(): bool
    {
        return $this->profitTotal !== null && bccomp($this->profitTotal, '0.00', 2) > 0;
    }
}
