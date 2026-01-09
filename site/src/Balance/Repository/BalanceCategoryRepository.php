<?php

namespace App\Balance\Repository;

use App\Balance\Entity\BalanceCategory;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BalanceCategoryRepository extends ServiceEntityRepository
{
    private const SORT_ORDER_STEP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceCategory::class);
    }

    /**
     * @return BalanceCategory[]
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
     * @return BalanceCategory[]
     */
    public function findTreeByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.level', 'ASC')
            ->addOrderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getNextSortOrder(Company $company, ?BalanceCategory $parent): int
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

    /**
     * @return BalanceCategory[]
     */
    public function findSiblings(Company $company, ?BalanceCategory $parent): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.sortOrder', 'ASC');

        if (null !== $parent) {
            $qb->andWhere('c.parent = :parent')
                ->setParameter('parent', $parent);
        } else {
            $qb->andWhere('c.parent IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function swapSortOrder(BalanceCategory $a, BalanceCategory $b): void
    {
        $aSort = $a->getSortOrder();
        $bSort = $b->getSortOrder();

        $a->setSortOrder($bSort);
        $b->setSortOrder($aSort);
    }
}
