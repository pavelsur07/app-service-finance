<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Восстанавливает/создаёт категории затрат из предопределённого списка OzonServiceCategoryMap.
 *
 * Для каждого известного кода:
 * - soft-deleted → восстанавливает (deletedAt = null, isActive = true)
 * - isActive = false → активирует
 * - не найдена → создаёт новую
 */
final class RestoreMarketplaceCostCategoriesAction
{
    public function __construct(
        private readonly MarketplaceCostCategoryRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Company $company, MarketplaceType $marketplace): int
    {
        $allCodes = OzonServiceCategoryMap::getAllCategoryCodes();
        $restored = 0;

        foreach ($allCodes as $code => $name) {
            $category = $this->repository->findOneBy([
                'company' => $company,
                'marketplace' => $marketplace,
                'code' => $code,
            ]);

            if ($category !== null) {
                if ($category->isDeleted()) {
                    $category->restore();
                    $restored++;
                } elseif (!$category->isActive()) {
                    $category->setIsActive(true);
                    $restored++;
                }

                continue;
            }

            $category = new MarketplaceCostCategory(
                Uuid::uuid7()->toString(),
                $company,
                $marketplace,
            );
            $category->setCode($code);
            $category->setName($name);

            $this->em->persist($category);
            $restored++;
        }

        $this->em->flush();

        return $restored;
    }
}
