<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;

final readonly class CostMappingResolver
{
    public function __construct(
        private UnitEconomyCostMappingRepositoryInterface $repository,
    ) {}

    public function resolve(
        string $companyId,
        string $marketplace,
        string $costCategoryId,
    ): UnitEconomyCostType {
        $marketplaceType = MarketplaceType::from($marketplace);

        $mapping = $this->repository->findOneByCategoryId($companyId, $marketplaceType, $costCategoryId);
        if ($mapping !== null) {
            return $mapping->getUnitEconomyCostType();
        }

        return UnitEconomyCostType::OTHER;
    }

    public function isAdvertisingCategory(
        string $companyId,
        string $marketplace,
        string $costCategoryId,
    ): bool {
        return $this->resolve($companyId, $marketplace, $costCategoryId)->isAdvertising();
    }
}
