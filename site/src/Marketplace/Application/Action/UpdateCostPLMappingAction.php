<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Finance\Facade\PLCategoryFacade;
use App\Marketplace\Entity\MarketplaceCostPLMapping;
use App\Marketplace\Exception\CostCategoryNotFoundException;
use App\Marketplace\Exception\CostMappingPLCategoryNotFoundException;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceCostPLMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Маппинг одной категории затрат на статью ОПиУ: создаёт или обновляет.
 * Без изменений значений запись в БД не идёт.
 */
final class UpdateCostPLMappingAction
{
    public function __construct(
        private readonly MarketplaceCostCategoryRepository $costCategoryRepository,
        private readonly MarketplaceCostPLMappingRepository $mappingRepository,
        private readonly PLCategoryFacade $plCategoryFacade,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $costCategoryId,
        ?string $plCategoryId,
        bool $includeInPl,
        int $sortOrder,
    ): MarketplaceCostPLMapping {
        $costCategory = $this->costCategoryRepository->findByIdAndCompanyId($companyId, $costCategoryId);
        if (null === $costCategory) {
            throw new CostCategoryNotFoundException($costCategoryId);
        }

        if (null !== $plCategoryId
            && (!Uuid::isValid($plCategoryId)
                || null === $this->plCategoryFacade->findByIdAndCompany($plCategoryId, $companyId))) {
            throw new CostMappingPLCategoryNotFoundException();
        }

        $mapping = $this->mappingRepository->findByCostCategory($companyId, $costCategoryId);

        if (null === $mapping) {
            $mapping = new MarketplaceCostPLMapping(
                Uuid::uuid7()->toString(),
                $companyId,
                $costCategory,
                $plCategoryId,
                $includeInPl,
                $sortOrder,
            );
            $this->em->persist($mapping);
        } elseif (!$mapping->update($plCategoryId, $includeInPl, $sortOrder)) {
            return $mapping;
        }

        $this->em->flush();

        return $mapping;
    }
}
