<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CashflowCategory>
 */
class CashflowCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashflowCategory::class);
    }

    /**
     * @return CashflowCategory[]
     */
    public function findRootByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.parent IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.sort', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Возвращает список категорий в порядке вложенности.
     *
     * @return CashflowCategory[]
     */
    public function findTreeByCompany(Company $company): array
    {
        $roots = $this->findRootByCompany($company);
        $result = [];
        foreach ($roots as $root) {
            $this->collectTree($root, $result);
        }

        return $result;
    }

    public function findSystemUnallocatedByCompany(Company $company): ?CashflowCategory
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.systemCode = :code')
            ->setParameter('company', $company)
            ->setParameter('code', CashflowCategory::SYSTEM_UNALLOCATED)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function collectTree(CashflowCategory $category, array &$result): void
    {
        $result[] = $category;
        foreach ($category->getChildren() as $child) {
            $this->collectTree($child, $result);
        }
    }
}
