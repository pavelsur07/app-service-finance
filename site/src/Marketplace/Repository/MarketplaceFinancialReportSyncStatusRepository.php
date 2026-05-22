<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final class MarketplaceFinancialReportSyncStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceFinancialReportSyncStatus::class);
    }

    public function save(MarketplaceFinancialReportSyncStatus $syncStatus): void
    {
        $this->getEntityManager()->persist($syncStatus);
    }

    public function findByConnectionAndDate(
        string $connectionId,
        string $companyId,
        \DateTimeImmutable $businessDate,
        string $reportType,
    ): ?MarketplaceFinancialReportSyncStatus {
        Assert::uuid($connectionId);
        Assert::uuid($companyId);

        return $this->createQueryBuilder('s')
            ->where('s.connectionId = :connectionId')
            ->andWhere('s.companyId = :companyId')
            ->andWhere('s.businessDate = :businessDate')
            ->andWhere('s.reportType = :reportType')
            ->setParameter('connectionId', $connectionId)
            ->setParameter('companyId', $companyId)
            ->setParameter('businessDate', $businessDate)
            ->setParameter('reportType', $reportType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findStatusEnumByDay(
        string $connectionId,
        string $companyId,
        \DateTimeImmutable $businessDate,
        string $reportType,
    ): ?FinancialReportSyncStatus {
        Assert::uuid($connectionId);
        Assert::uuid($companyId);

        $status = $this->createQueryBuilder('s')
            ->select('s.status')
            ->where('s.connectionId = :connectionId')
            ->andWhere('s.companyId = :companyId')
            ->andWhere('s.businessDate = :businessDate')
            ->andWhere('s.reportType = :reportType')
            ->setParameter('connectionId', $connectionId)
            ->setParameter('companyId', $companyId)
            ->setParameter('businessDate', $businessDate)
            ->setParameter('reportType', $reportType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $status) {
            return null;
        }

        if (is_array($status)) {
            $status = $status['status'] ?? null;
        }

        if ($status instanceof FinancialReportSyncStatus) {
            return $status;
        }

        return is_string($status) ? FinancialReportSyncStatus::from($status) : null;
    }

    /**
     * @return list<MarketplaceFinancialReportSyncStatus>
     */
    public function findStatusesForDateRange(
        string $companyId,
        string $connectionId,
        string $reportType,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.connectionId = :connectionId')
            ->andWhere('s.reportType = :reportType')
            ->andWhere('s.businessDate BETWEEN :from AND :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionId', $connectionId)
            ->setParameter('reportType', $reportType)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.businessDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    public function findRetryDueDays(
        string $companyId,
        string $connectionId,
        string $reportType,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now,
        int $limit,
    ): array {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        if ($limit <= 0) {
            return [];
        }

        $rows = $this->createQueryBuilder('s')
            ->select('s.businessDate')
            ->where('s.companyId = :companyId')
            ->andWhere('s.connectionId = :connectionId')
            ->andWhere('s.reportType = :reportType')
            ->andWhere('s.businessDate BETWEEN :from AND :to')
            ->andWhere('s.status = :failedStatus')
            ->andWhere('(s.nextRetryAt IS NULL OR s.nextRetryAt <= :now)')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionId', $connectionId)
            ->setParameter('reportType', $reportType)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('failedStatus', FinancialReportSyncStatus::FAILED)
            ->setParameter('now', $now)
            ->orderBy('s.businessDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $days = [];
        foreach ($rows as $row) {
            $date = $row['businessDate'] ?? null;
            if ($date instanceof \DateTimeImmutable) {
                $days[] = $date;
            }
        }

        return $days;
    }


    public function findByRawDocumentId(string $companyId, string $rawDocumentId): ?MarketplaceFinancialReportSyncStatus
    {
        Assert::uuid($companyId);
        Assert::uuid($rawDocumentId);

        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.rawDocumentId = :rawDocumentId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrCreateForDay(
        string $connectionId,
        string $companyId,
        MarketplaceType $marketplace,
        string $reportType,
        string $apiEndpoint,
        \DateTimeImmutable $businessDate,
    ): MarketplaceFinancialReportSyncStatus {
        Assert::uuid($connectionId);
        Assert::uuid($companyId);

        $syncStatus = $this->createQueryBuilder('s')
            ->where('s.connectionId = :connectionId')
            ->andWhere('s.companyId = :companyId')
            ->andWhere('s.businessDate = :businessDate')
            ->andWhere('s.reportType = :reportType')
            ->setParameter('connectionId', $connectionId)
            ->setParameter('companyId', $companyId)
            ->setParameter('businessDate', $businessDate)
            ->setParameter('reportType', $reportType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($syncStatus instanceof MarketplaceFinancialReportSyncStatus) {
            return $syncStatus;
        }

        $syncStatus = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            $companyId,
            $connectionId,
            $marketplace,
            $reportType,
            $apiEndpoint,
            $businessDate,
        );

        $this->save($syncStatus);

        return $syncStatus;
    }
}
