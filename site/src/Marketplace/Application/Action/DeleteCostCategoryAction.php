<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Exception\CostCategoryHasCostsException;
use App\Marketplace\Exception\CostCategoryNotFoundException;
use App\Marketplace\Exception\SystemCostCategoryDeletionException;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use App\Marketplace\Repository\MarketplaceCostPLMappingRepository;
use App\Marketplace\Repository\MarketplaceCostRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Мягкое удаление пользовательской категории затрат без затрат вместе с её маппингом.
 */
final class DeleteCostCategoryAction
{
    public function __construct(
        private readonly MarketplaceCostCategoryRepository $costCategoryRepository,
        private readonly MarketplaceCostPLMappingRepository $mappingRepository,
        private readonly MarketplaceCostRepository $costRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $costCategoryId): MarketplaceCostCategory
    {
        $category = $this->costCategoryRepository->findByIdAndCompanyId($companyId, $costCategoryId);
        if (null === $category) {
            throw new CostCategoryNotFoundException($costCategoryId);
        }

        if ($category->isSystem()) {
            throw new SystemCostCategoryDeletionException();
        }

        $costsCount = $this->costRepository->count(['category' => $category]);
        if ($costsCount > 0) {
            throw new CostCategoryHasCostsException($category->getName(), $costsCount);
        }

        $mapping = $this->mappingRepository->findByCostCategory($companyId, $costCategoryId);
        if (null !== $mapping) {
            $this->em->remove($mapping);
        }

        $category->softDelete();
        $this->em->flush();

        return $category;
    }
}
