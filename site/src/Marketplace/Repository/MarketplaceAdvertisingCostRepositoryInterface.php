<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceAdvertisingCost;
use App\Marketplace\Enum\AdvertisingType;

interface MarketplaceAdvertisingCostRepositoryInterface
{
    public function save(MarketplaceAdvertisingCost $cost): void;

    /**
     * @return MarketplaceAdvertisingCost[]
     */
    public function findByListingAndDate(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): array;

    /**
     * @return MarketplaceAdvertisingCost[]
     */
    public function findByCompanyAndDate(
        string $companyId,
        \DateTimeImmutable $date,
    ): array;

    public function findOneByUniqueKey(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
        AdvertisingType $type,
        string $campaignId,
    ): ?MarketplaceAdvertisingCost;
}
