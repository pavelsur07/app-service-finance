<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final class MarketplaceFinancialReportSyncStatusRepository extends ServiceEntityRepository implements MarketplaceFinancialReportSyncStatusLookupInterface
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
            ->andWhere('s.lastErrorStatusCode = :rateLimitStatusCode')
            ->andWhere('s.lastErrorClass = :rateLimitErrorClass')
            ->setParameter('companyId', $companyId)
            ->setParameter('connectionId', $connectionId)
            ->setParameter('reportType', $reportType)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('failedStatus', FinancialReportSyncStatus::FAILED)
            ->setParameter('now', $now)
            ->setParameter('rateLimitStatusCode', 429)
            ->setParameter('rateLimitErrorClass', MarketplaceRateLimitException::class)
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

    public function claimForQueue(
        string $connectionId,
        string $companyId,
        MarketplaceType $marketplace,
        string $reportType,
        string $apiEndpoint,
        \DateTimeImmutable $businessDate,
        FinancialReportSyncMode $mode,
        bool $forceRefresh,
        \DateTimeImmutable $now,
    ): ?MarketplaceFinancialReportSyncStatus {
        Assert::uuid($connectionId);
        Assert::uuid($companyId);

        $em = $this->getEntityManager();

        try {
            $em->beginTransaction();

            $syncStatus = $this->createQueryBuilder('s')
                ->where('s.connectionId = :connectionId')
                ->andWhere('s.businessDate = :businessDate')
                ->andWhere('s.reportType = :reportType')
                ->setParameter('connectionId', $connectionId)
                ->setParameter('businessDate', $businessDate)
                ->setParameter('reportType', $reportType)
                ->setMaxResults(1)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (!$syncStatus instanceof MarketplaceFinancialReportSyncStatus) {
                $syncStatus = new MarketplaceFinancialReportSyncStatus(
                    Uuid::uuid7()->toString(),
                    $companyId,
                    $connectionId,
                    $marketplace,
                    $reportType,
                    $apiEndpoint,
                    $businessDate,
                );

                $em->persist($syncStatus);
            } elseif (!$this->canClaimForQueue($syncStatus, $mode, $forceRefresh, $now)) {
                $em->commit();

                return null;
            }

            $syncStatus->markQueued($mode, $forceRefresh);
            $em->persist($syncStatus);
            $em->flush();
            $em->commit();

            return $syncStatus;
        } catch (UniqueConstraintViolationException) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            return null;
        } catch (\Throwable $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            throw $e;
        }
    }

    private function canClaimForQueue(
        MarketplaceFinancialReportSyncStatus $syncStatus,
        FinancialReportSyncMode $mode,
        bool $forceRefresh,
        \DateTimeImmutable $now,
    ): bool {
        $status = $syncStatus->getStatus();

        if (\in_array($status, [
            FinancialReportSyncStatus::QUEUED,
            FinancialReportSyncStatus::LOADING,
            FinancialReportSyncStatus::RAW_LOADED,
            FinancialReportSyncStatus::PROCESSING,
        ], true)) {
            return false;
        }

        if (FinancialReportSyncStatus::FAILED === $status
            && null !== $syncStatus->getNextRetryAt()
            && $syncStatus->getNextRetryAt() > $now
        ) {
            return false;
        }

        if (!$forceRefresh
            && \in_array($mode, [FinancialReportSyncMode::DAILY, FinancialReportSyncMode::MISSING, FinancialReportSyncMode::INITIAL], true)
            && \in_array($status, [FinancialReportSyncStatus::SUCCESS, FinancialReportSyncStatus::EMPTY], true)
        ) {
            return false;
        }

        if (\in_array($status, [
            FinancialReportSyncStatus::AUTH_FAILED,
            FinancialReportSyncStatus::FAILED_FINAL,
            FinancialReportSyncStatus::CONFLICT,
        ], true)) {
            return $forceRefresh && FinancialReportSyncMode::MANUAL === $mode;
        }

        return true;
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
