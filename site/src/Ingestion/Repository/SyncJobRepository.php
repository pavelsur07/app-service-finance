<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\SyncJobStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncJob>
 */
final class SyncJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncJob::class);
    }

    public function findByIdAndCompany(string $id, string $companyId): ?SyncJob
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.id = :id')
            ->andWhere('job.companyId = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<SyncJob>
     */
    public function findOpenChildrenOf(string $parentJobId, string $companyId): array
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.parentJobId = :parentJobId')
            ->andWhere('job.companyId = :companyId')
            ->andWhere('job.status IN (:statuses)')
            ->setParameter('parentJobId', $parentJobId)
            ->setParameter('companyId', $companyId)
            ->setParameter('statuses', [SyncJobStatus::OPEN->value, SyncJobStatus::RUNNING->value])
            ->orderBy('job.windowFrom', 'ASC')
            ->addOrderBy('job.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countChildrenByStatus(string $parentJobId, string $companyId, SyncJobStatus $status): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.parentJobId = :parentJobId')
            ->andWhere('job.companyId = :companyId')
            ->andWhere('job.status = :status')
            ->setParameter('parentJobId', $parentJobId)
            ->setParameter('companyId', $companyId)
            ->setParameter('status', $status->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLatestForResource(
        string $companyId,
        string $connectionRef,
        string $resourceType,
        string $shopRef = '',
    ): ?SyncJob {
        return $this->createQueryBuilder('job')
            ->andWhere('job.companyId = :companyId')
            ->andWhere('job.connectionRef = :connectionRef')
            ->andWhere('job.resourceType = :resourceType')
            ->andWhere('job.shopRef = :shopRef')
            ->andWhere('job.status IN (:statuses)')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionRef', $connectionRef)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('shopRef', $shopRef)
            ->setParameter('statuses', [SyncJobStatus::OPEN->value, SyncJobStatus::RUNNING->value])
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
