<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\PLDirtyPeriod;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PLDirtyPeriod>
 */
final class PLDirtyPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PLDirtyPeriod::class);
    }

    public function findOne(string $companyId, int $year, int $month, string $shopRef = ''): ?PLDirtyPeriod
    {
        return $this->createQueryBuilder('period')
            ->andWhere('period.companyId = :companyId')
            ->andWhere('period.periodYear = :year')
            ->andWhere('period.periodMonth = :month')
            ->andWhere('period.shopRef = :shopRef')
            ->setParameter('companyId', $companyId)
            ->setParameter('year', $year)
            ->setParameter('month', $month)
            ->setParameter('shopRef', $shopRef)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<PLDirtyPeriod>
     */
    public function findPending(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('period')
            ->andWhere('period.status = :status')
            ->setParameter('status', PLDirtyPeriodStatus::PENDING->value)
            ->orderBy('period.markedAt', 'ASC')
            ->addOrderBy('period.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PLDirtyPeriod>
     */
    public function findForCompany(string $companyId): array
    {
        return $this->createQueryBuilder('period')
            ->andWhere('period.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('period.markedAt', 'DESC')
            ->addOrderBy('period.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PLDirtyPeriod>
     */
    public function findPendingForCompany(string $companyId): array
    {
        return $this->createQueryBuilder('period')
            ->andWhere('period.companyId = :companyId')
            ->andWhere('period.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', PLDirtyPeriodStatus::PENDING->value)
            ->orderBy('period.markedAt', 'ASC')
            ->addOrderBy('period.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(string $companyId, PLDirtyPeriodStatus $status): int
    {
        return (int) $this->createQueryBuilder('period')
            ->select('COUNT(period.id)')
            ->andWhere('period.companyId = :companyId')
            ->andWhere('period.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
