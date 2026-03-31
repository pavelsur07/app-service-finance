<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AddCostMappingAction
{
    public function __construct(
        private readonly UnitEconomyCostMappingRepositoryInterface $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(
        string $companyId,
        string $marketplace,
        string $costCategoryId,
        string $costCategoryName,
        UnitEconomyCostType $unitEconomyCostType,
    ): UnitEconomyCostMapping {
        $existing = $this->repository->findOneByCategoryId($companyId, $marketplace, $costCategoryId);

        if ($existing !== null) {
            throw new \DomainException('Маппинг для данной категории уже существует');
        }

        $mapping = new UnitEconomyCostMapping(
            Uuid::uuid7()->toString(),
            $companyId,
            MarketplaceType::from($marketplace),
            $costCategoryId,
            $costCategoryName,
            $unitEconomyCostType,
        );

        $this->repository->save($mapping);
        $this->entityManager->flush();

        return $mapping;
    }
}
