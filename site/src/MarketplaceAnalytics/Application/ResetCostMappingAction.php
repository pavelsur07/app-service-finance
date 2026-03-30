<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Repository\UnitEconomyCostMappingRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final class ResetCostMappingAction
{
    public function __construct(
        private readonly UnitEconomyCostMappingRepositoryInterface $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(
        string $companyId,
        string $mappingId,
    ): UnitEconomyCostMapping {
        $mapping = $this->repository->findByIdAndCompany($mappingId, $companyId);

        if ($mapping === null) {
            throw new \DomainException('Маппинг не найден');
        }

        if ($mapping->isSystem()) {
            throw new \DomainException('Маппинг уже имеет системное значение');
        }

        $systemMapping = $this->repository->findSystemMapping(
            $mapping->getMarketplace(),
            $mapping->getCostCategoryCode(),
        );

        if ($systemMapping === null) {
            throw new \DomainException('Системный маппинг для данного кода не найден');
        }

        $mapping->remapTo($systemMapping->getUnitEconomyCostType());

        $this->entityManager->flush();

        return $mapping;
    }
}
