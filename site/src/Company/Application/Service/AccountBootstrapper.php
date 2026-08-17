<?php

declare(strict_types=1);

namespace App\Company\Application\Service;

use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AccountBootstrapper
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PLCategoryRepository $plCategories,
    ) {
    }

    public function ensurePlSeeded(Company $company): bool
    {
        if ($this->plCategories->count(['company' => $company]) > 0) {
            return false;
        }

        $this->seedPL($company);
        $this->em->flush();

        return true;
    }

    private function seedPL(Company $company): void
    {
        $tree = [
            'Выручка' => ['Маркетплейсы', 'Собственные каналы'],
            'Себестоимость' => [],
            'Комиссии/Логистика' => [],
            'Маркетинг' => [],
            'Административные (G&A)' => [],
        ];

        $rootSort = 10;
        foreach ($tree as $rootName => $children) {
            $root = $this->ensurePL($company, $rootName, null, $rootSort);
            $rootSort += 10;

            $childSort = 10;
            foreach ($children as $childName) {
                $this->ensurePL($company, $childName, $root, $childSort);
                $childSort += 10;
            }
        }
    }

    private function ensurePL(
        Company $company,
        string $name,
        ?PLCategory $parent,
        int $sortOrder,
    ): PLCategory {
        $existing = $this->plCategories->findOneBy([
            'company' => $company,
            'name' => $name,
            'parent' => $parent,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $category = new PLCategory(
            id: Uuid::uuid4()->toString(),
            company: $company,
        );
        $category->setName($name);
        $category->setParent($parent);
        $category->setSortOrder($sortOrder);

        $this->em->persist($category);

        return $category;
    }
}
