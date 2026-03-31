<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;

final readonly class DefaultCostMappingSeedPolicy
{
    public function __construct(
        private UnitEconomyCostMappingRepositoryInterface $repository,
    ) {}

    public function seedForCompanyAndMarketplace(
        string $companyId,
        string $marketplace,
    ): void {
        // Seeding is no longer needed — mappings are now created
        // explicitly via the UI using real cost category IDs.
    }
}
