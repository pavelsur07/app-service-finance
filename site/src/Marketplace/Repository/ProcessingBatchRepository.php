<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\ProcessingBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessingBatch>
 */
class ProcessingBatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessingBatch::class);
    }

    /**
     * Найти батч по ID raw документа
     */
    public function findByRawDocumentId(string $rawDocumentId): ?ProcessingBatch
    {
        return $this->createQueryBuilder('b')
            ->where('b.rawDocument = :rawDocId')
            ->setParameter('rawDocId', $rawDocumentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Получить последние батчи компании
     */
    public function findRecentByCompany(Company $company, int $limit = 20): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->setParameter('company', $company)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти незавершенные батчи
     */
    public function findIncomplete(Company $company): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->andWhere('b.status IN (:statuses)')
            ->setParameter('company', $company)
            ->setParameter('statuses', ['pending', 'parsing', 'processing'])
            ->orderBy('b.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти батчи с ошибками
     */
    public function findWithFailures(Company $company, int $limit = 50): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->andWhere('b.failedRecords > 0')
            ->setParameter('company', $company)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Получить статистику обработки по компании
     */
    public function getProcessingStats(Company $company): array
    {
        $result = $this->createQueryBuilder('b')
            ->select([
                'COUNT(b.id) as total_batches',
                'SUM(b.totalRecords) as total_records',
                'SUM(b.processedRecords) as processed_records',
                'SUM(b.failedRecords) as failed_records',
                'SUM(b.skippedRecords) as skipped_records',
            ])
            ->where('b.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleResult();

        return [
            'total_batches' => (int) ($result['total_batches'] ?? 0),
            'total_records' => (int) ($result['total_records'] ?? 0),
            'processed_records' => (int) ($result['processed_records'] ?? 0),
            'failed_records' => (int) ($result['failed_records'] ?? 0),
            'skipped_records' => (int) ($result['skipped_records'] ?? 0),
        ];
    }

    /**
     * Получить батчи за период
     */
    public function findByDateRange(
        Company $company,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        return $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->andWhere('b.createdAt >= :from')
            ->andWhere('b.createdAt <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(ProcessingBatch $batch): void
    {
        $this->getEntityManager()->persist($batch);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
