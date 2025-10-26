<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\PLCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PLCategoryRepository extends ServiceEntityRepository
{
    private const SORT_ORDER_STEP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLCategory::class);
    }

    /**
     * @return PLCategory[]
     */
    public function findRootByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.parent IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PLCategory[]
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

    private function collectTree(PLCategory $category, array &$result): void
    {
        $result[] = $category;
        foreach ($category->getChildren() as $child) {
            $this->collectTree($child, $result);
        }
    }

    public function getNextSortOrder(Company $company, ?PLCategory $parent): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('MAX(c.sortOrder) as maxSortOrder')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company);

        if ($parent) {
            $qb->andWhere('c.parent = :parent')
                ->setParameter('parent', $parent);
        } else {
            $qb->andWhere('c.parent IS NULL');
        }

        $maxSortOrder = $qb->getQuery()->getSingleScalarResult();
        $maxSortOrder = null !== $maxSortOrder ? (int) $maxSortOrder : null;

        return ($maxSortOrder ?? 0) + self::SORT_ORDER_STEP;
    }
}
