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

    /**
     * Задачи, застрявшие в нерабочем состоянии: OPEN/RUNNING без движения
     * дольше порога.
     *
     * Нужны потому, что findLatestForResource() считает активной любую такую
     * задачу БЕЗ ограничения по возрасту, и StartIncrementalAction бросает на
     * неё ActiveBackfillExistsException. Воркер, убитый по SIGKILL или OOM, не
     * выполняет finally, задача остаётся RUNNING, и ресурс перестаёт
     * загружаться молча — навсегда.
     *
     * @companyScopeExempt уборщик обходит все компании: зависшая задача одной
     *                     компании не должна ждать, пока кто-то откроет её
     *                     кабинет
     *
     * @return list<SyncJob>
     */
    public function findStaleActive(\DateTimeImmutable $olderThan, int $limit): array
    {
        /** @var list<SyncJob> $jobs */
        $jobs = $this->createQueryBuilder('job')
            ->andWhere('job.status IN (:statuses)')
            ->andWhere('job.updatedAt < :olderThan')
            ->setParameter('statuses', [SyncJobStatus::OPEN->value, SyncJobStatus::RUNNING->value])
            ->setParameter('olderThan', $olderThan)
            ->orderBy('job.updatedAt', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();

        return $jobs;
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
