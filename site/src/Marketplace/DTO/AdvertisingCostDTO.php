<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

use App\Marketplace\Entity\MarketplaceAdvertisingCost;
use App\Marketplace\Enum\AdvertisingType;
use App\Marketplace\Enum\MarketplaceType;

final readonly class AdvertisingCostDTO
{
    public function __construct(
        public string $id,
        public string $companyId,
        public string $listingId,
        public MarketplaceType $marketplace,
        public \DateTimeImmutable $date,
        public AdvertisingType $advertisingType,
        public string $amount,
        public array $analyticsData,
        public string $externalCampaignId,
    ) {}

    public static function fromEntity(MarketplaceAdvertisingCost $entity): self
    {
        return new self(
            id: $entity->getId(),
            companyId: $entity->getCompanyId(),
            listingId: $entity->getListingId(),
            marketplace: $entity->getMarketplace(),
            date: $entity->getDate(),
            advertisingType: $entity->getAdvertisingType(),
            amount: $entity->getAmount(),
            analyticsData: $entity->getAnalyticsData(),
            externalCampaignId: $entity->getExternalCampaignId(),
        );
    }
}
