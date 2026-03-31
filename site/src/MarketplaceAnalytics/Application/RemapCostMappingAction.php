<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final class RemapCostMappingAction
{
    public function __construct(
        private readonly UnitEconomyCostMappingRepositoryInterface $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(
        string $companyId,
        string $mappingId,
        UnitEconomyCostType $newType,
    ): UnitEconomyCostMapping {
        $mapping = $this->repository->findById($mappingId, $companyId);

        if ($mapping === null) {
            throw new \DomainException('Маппинг не найден');
        }

        $mapping->remapTo($newType);

        $this->entityManager->flush();

        return $mapping;
    }
}
