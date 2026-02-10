<?php

namespace App\Cash\Service\Category;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class CashflowSystemCategoryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashflowCategoryRepository $cashflowCategoryRepository,
    ) {
    }

    public function getOrCreateUnallocated(Company $company): CashflowCategory
    {
        $existing = $this->cashflowCategoryRepository->findSystemUnallocatedByCompany($company);
        if (null !== $existing) {
            return $existing;
        }

        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Не распределено');
        $category->setParent(null);
        $category->setSort(1000000);
        $category->setSystemCode(CashflowCategory::SYSTEM_UNALLOCATED);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }
}

