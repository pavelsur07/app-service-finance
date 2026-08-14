<?php

declare(strict_types=1);

namespace App\Balance\Repository;

use App\Balance\Entity\BalanceCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class BalanceCategoryRepository extends ServiceEntityRepository implements BalanceCategoryRepositoryInterface
{
    private const SORT_ORDER_STEP = 10;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceCategory::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?BalanceCategory
    {
        return $this->findOneBy(['id' => $id, 'companyId' => $companyId]);
    }

    /**
     * @return BalanceCategory[]
     */
    public function findRootByCompany(string $companyId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('c.parent IS NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BalanceCategory[]
     */
    public function findTreeByCompany(string $companyId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('c.level', 'ASC')
            ->addOrderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getNextSortOrder(string $companyId, ?BalanceCategory $parent): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('MAX(c.sortOrder) as maxSortOrder')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('companyId', $companyId);

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
    public function findSiblings(string $companyId, ?BalanceCategory $parent): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.companyId = :companyId')
            ->setParameter('companyId', $companyId)
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

    public function existsWithCode(string $companyId, string $code, ?string $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.companyId = :companyId')
            ->andWhere('c.code = :code')
            ->setParameter('companyId', $companyId)
            ->setParameter('code', $code);

        if (null !== $excludeId) {
            $qb->andWhere('c.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
