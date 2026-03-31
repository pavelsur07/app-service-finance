<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Repository;

use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;

interface UnitEconomyCostMappingRepositoryInterface
{
    public function save(UnitEconomyCostMapping $mapping): void;

    public function findById(
        string $id,
        string $companyId,
    ): ?UnitEconomyCostMapping;

    public function findOneByCategoryId(
        string $companyId,
        string $marketplace,
        string $costCategoryId,
    ): ?UnitEconomyCostMapping;

    /**
     * @return UnitEconomyCostMapping[]
     */
    public function findByCompanyAndMarketplace(
        string $companyId,
        string $marketplace,
    ): array;

    /**
     * @return array{items: UnitEconomyCostMapping[], total: int}
     */
    public function findPaginated(
        string $companyId,
        ?string $marketplace,
        int $page,
        int $perPage,
    ): array;

    public function delete(
        string $id,
        string $companyId,
    ): void;
}
