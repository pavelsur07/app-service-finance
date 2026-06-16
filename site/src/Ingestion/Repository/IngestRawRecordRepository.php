<?php

declare(strict_types=1);

namespace App\Ingestion\Repository;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
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

    public function findLatestByCompanySourceExternalId(
        string $companyId,
        IngestSource $source,
        string $externalId,
    ): ?IngestRawRecord {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.source = :source')
            ->andWhere('record.externalId = :externalId')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
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
        string $externalId,
        string $hash,
    ): ?IngestRawRecord {
        return $this->createQueryBuilder('record')
            ->andWhere('record.companyId = :companyId')
            ->andWhere('record.source = :source')
            ->andWhere('record.externalId = :externalId')
            ->andWhere('record.hash = :hash')
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('externalId', $externalId)
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
