<?php

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\ProcessingBatch;
use App\Marketplace\Entity\ReconciliationLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReconciliationLog>
 */
class ReconciliationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReconciliationLog::class);
    }

    /**
     * Найти все логи для батча
     */
    public function findByBatch(ProcessingBatch $batch): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.processingBatch = :batch')
            ->setParameter('batch', $batch)
            ->orderBy('l.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти провалившиеся проверки для батча
     */
    public function findFailedByBatch(ProcessingBatch $batch): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.processingBatch = :batch')
            ->andWhere('l.passed = :passed')
            ->setParameter('batch', $batch)
            ->setParameter('passed', false)
            ->orderBy('l.checkedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Найти последнюю проверку определенного типа для батча
     */
    public function findLatestByType(ProcessingBatch $batch, string $checkType): ?ReconciliationLog
    {
        return $this->createQueryBuilder('l')
            ->where('l.processingBatch = :batch')
            ->andWhere('l.checkType = :checkType')
            ->setParameter('batch', $batch)
            ->setParameter('checkType', $checkType)
            ->orderBy('l.checkedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Проверить прошли ли все проверки для батча
     */
    public function areAllChecksPassed(ProcessingBatch $batch): bool
    {
        $failedCount = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.processingBatch = :batch')
            ->andWhere('l.passed = :passed')
            ->setParameter('batch', $batch)
            ->setParameter('passed', false)
            ->getQuery()
            ->getSingleScalarResult();

        return $failedCount === 0;
    }

    /**
     * Получить сводку по проверкам
     */
    public function getChecksSummary(ProcessingBatch $batch): array
    {
        $results = $this->createQueryBuilder('l')
            ->select([
                'l.checkType',
                'l.passed',
                'COUNT(l.id) as count',
            ])
            ->where('l.processingBatch = :batch')
            ->setParameter('batch', $batch)
            ->groupBy('l.checkType, l.passed')
            ->getQuery()
            ->getResult();

        $summary = [];
        foreach ($results as $result) {
            $checkType = $result['checkType'];
            $passed = $result['passed'];

            if (!isset($summary[$checkType])) {
                $summary[$checkType] = [
                    'passed' => 0,
                    'failed' => 0,
                ];
            }

            if ($passed) {
                $summary[$checkType]['passed'] += (int) $result['count'];
            } else {
                $summary[$checkType]['failed'] += (int) $result['count'];
            }
        }

        return $summary;
    }

    public function save(ReconciliationLog $log): void
    {
        $this->getEntityManager()->persist($log);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
