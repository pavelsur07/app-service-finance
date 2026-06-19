<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\RawNormalizationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestRawRecord>
 */
final class IngestRawRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestRawRecord::class);
    }

    public function findOneByIdAndCompany(string $companyId, string $rawRecordId): ?IngestRawRecord
    {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.id = :rawRecordId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawRecordId', $rawRecordId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByIdAndCompany(string $rawRecordId, string $companyId): ?IngestRawRecord
    {
        return $this->findOneByIdAndCompany($companyId, $rawRecordId);
    }

    public function findLatestByCompanySourceExternalId(
        string $companyId,
        IngestSource $source,
        string $resourceType,
        string $externalId,
    ): ?IngestRawRecord {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.source = :source')
            ->andWhere('record.resourceType = :resourceType')
            ->andWhere('record.externalId = :externalId')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalId', $externalId)
            ->orderBy('record.fetchedAt', 'DESC')
            ->addOrderBy('record.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByCompanySourceExternalIdAndHash(
        string $companyId,
        IngestSource $source,
        string $resourceType,
        string $externalId,
        string $hash,
    ): ?IngestRawRecord {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.source = :source')
            ->andWhere('record.resourceType = :resourceType')
            ->andWhere('record.externalId = :externalId')
            ->andWhere('record.hash = :hash')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('resourceType', $resourceType)
            ->setParameter('externalId', $externalId)
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<IngestRawRecord>
     */
    public function findStuckPending(\DateTimeImmutable $olderThan, int $limit): array
    {
        $limit = max(1, min(200, $limit));

        return $this->createQueryBuilder('record')
            ->andWhere('record.normalizationStatus = :status')
            ->andWhere('record.fetchedAt < :olderThan')
            ->setParameter('status', RawNormalizationStatus::PENDING->value)
            ->setParameter('olderThan', $olderThan)
            ->orderBy('record.fetchedAt', 'ASC')
            ->addOrderBy('record.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
