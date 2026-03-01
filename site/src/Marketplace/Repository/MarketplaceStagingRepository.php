<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceStaging;
use App\Marketplace\Entity\ProcessingBatch;
use App\Marketplace\Enum\ProcessingStatus;
use App\Marketplace\Enum\StagingRecordType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketplaceStaging>
 */
class MarketplaceStagingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceStaging::class);
    }

    /**
     * Найти записи ожидающие обработки для батча
     */
    public function findPendingByBatch(ProcessingBatch $batch, int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.processingBatch = :batch')
            ->andWhere('s.processingStatus = :status')
            ->setParameter('batch', $batch)
            ->setParameter('status', ProcessingStatus::PENDING)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти записи определенного типа ожидающие обработки
     */
    public function findPendingByType(
        ProcessingBatch $batch,
        StagingRecordType $recordType,
        int $limit = 100
    ): array {
        return $this->createQueryBuilder('s')
            ->where('s.processingBatch = :batch')
            ->andWhere('s.recordType = :type')
            ->andWhere('s.processingStatus = :status')
            ->setParameter('batch', $batch)
            ->setParameter('type', $recordType)
            ->setParameter('status', ProcessingStatus::PENDING)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти провалившиеся записи (для переобработки)
     */
    public function findFailedByBatch(ProcessingBatch $batch, int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.processingBatch = :batch')
            ->andWhere('s.processingStatus = :status')
            ->setParameter('batch', $batch)
            ->setParameter('status', ProcessingStatus::FAILED)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Проверить существует ли запись с таким source_record_id
     */
    public function existsBySourceRecordId(string $marketplace, string $sourceRecordId): bool
    {
        return $this->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->where('s.marketplace = :marketplace')
                ->andWhere('s.sourceRecordId = :sourceRecordId')
                ->setParameter('marketplace', $marketplace)
                ->setParameter('sourceRecordId', $sourceRecordId)
                ->getQuery()
                ->getSingleScalarResult() > 0;
    }

    /**
     * Найти дубликаты по source_record_id (bulk)
     */
    public function findExistingSourceRecordIds(string $marketplace, array $sourceRecordIds): array
    {
        if (empty($sourceRecordIds)) {
            return [];
        }

        $results = $this->createQueryBuilder('s')
            ->select('s.sourceRecordId')
            ->where('s.marketplace = :marketplace')
            ->andWhere('s.sourceRecordId IN (:sourceRecordIds)')
            ->setParameter('marketplace', $marketplace)
            ->setParameter('sourceRecordIds', $sourceRecordIds)
            ->getQuery()
            ->getScalarResult();

        return array_column($results, 'sourceRecordId');
    }

    /**
     * Получить статистику по статусам для батча
     */
    public function getStatusStats(ProcessingBatch $batch): array
    {
        $results = $this->createQueryBuilder('s')
            ->select([
                's.processingStatus as status',
                'COUNT(s.id) as count',
            ])
            ->where('s.processingBatch = :batch')
            ->setParameter('batch', $batch)
            ->groupBy('s.processingStatus')
            ->getQuery()
            ->getResult();

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($results as $result) {
            $stats[$result['status']->value] = (int) $result['count'];
        }

        return $stats;
    }

    /**
     * Получить статистику по типам записей для батча
     */
    public function getTypeStats(ProcessingBatch $batch): array
    {
        $results = $this->createQueryBuilder('s')
            ->select([
                's.recordType as type',
                'COUNT(s.id) as count',
                'SUM(s.amount) as total_amount',
            ])
            ->where('s.processingBatch = :batch')
            ->setParameter('batch', $batch)
            ->groupBy('s.recordType')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $result) {
            $stats[$result['type']->value] = [
                'count' => (int) $result['count'],
                'total_amount' => (float) ($result['total_amount'] ?? 0),
            ];
        }

        return $stats;
    }

    /**
     * Найти записи без связанного листинга
     */
    public function findWithoutListing(
        ProcessingBatch $batch,
        ?StagingRecordType $recordType = null,
        int $limit = 100
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->where('s.processingBatch = :batch')
            ->andWhere('s.listing IS NULL')
            ->setParameter('batch', $batch)
            ->setMaxResults($limit);

        if ($recordType !== null) {
            $qb->andWhere('s.recordType = :type')
                ->setParameter('type', $recordType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Получить детали ошибок валидации
     */
    public function getValidationErrors(ProcessingBatch $batch): array
    {
        return $this->createQueryBuilder('s')
            ->select([
                's.id',
                's.sourceRecordId',
                's.recordType',
                's.validationErrors',
            ])
            ->where('s.processingBatch = :batch')
            ->andWhere('s.processingStatus = :status')
            ->andWhere('s.validationErrors IS NOT NULL')
            ->setParameter('batch', $batch)
            ->setParameter('status', ProcessingStatus::FAILED)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Удалить все staging записи батча (cleanup после успешной обработки)
     */
    public function deleteByBatch(ProcessingBatch $batch): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.processingBatch = :batch')
            ->setParameter('batch', $batch)
            ->getQuery()
            ->execute();
    }

    /**
     * Сбросить статус failed записей в pending (для переобработки)
     */
    public function resetFailedToPending(ProcessingBatch $batch): int
    {
        return $this->getEntityManager()
            ->createQuery(
                'UPDATE ' . MarketplaceStaging::class . ' s
                 SET s.processingStatus = :newStatus,
                     s.validationErrors = NULL,
                     s.processedAt = NULL
                 WHERE s.processingBatch = :batch
                 AND s.processingStatus = :oldStatus'
            )
            ->setParameter('newStatus', ProcessingStatus::PENDING)
            ->setParameter('oldStatus', ProcessingStatus::FAILED)
            ->setParameter('batch', $batch)
            ->execute();
    }

    public function save(MarketplaceStaging $staging): void
    {
        $this->getEntityManager()->persist($staging);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function clear(): void
    {
        $this->getEntityManager()->clear();
    }
}
