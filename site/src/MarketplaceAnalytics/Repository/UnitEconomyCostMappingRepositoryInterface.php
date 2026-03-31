<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;

interface UnitEconomyCostMappingRepositoryInterface
{
    public function save(UnitEconomyCostMapping $mapping): void;

    /**
     * @return UnitEconomyCostMapping[]
     */
    public function findByCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
    ): array;

    public function findOneByCategoryId(
        string $companyId,
        MarketplaceType $marketplace,
        string $costCategoryId,
    ): ?UnitEconomyCostMapping;

    public function hasCompanyMappings(
        string $companyId,
        MarketplaceType $marketplace,
    ): bool;

    /**
     * @return array{items: UnitEconomyCostMapping[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?MarketplaceType $marketplace,
        int $page,
        int $perPage,
    ): array;

    public function findByIdAndCompany(
        string $id,
        string $companyId,
    ): ?UnitEconomyCostMapping;

    public function delete(
        UnitEconomyCostMapping $mapping,
        string $companyId,
    ): void;
}
