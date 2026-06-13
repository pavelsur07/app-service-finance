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
    private const ADVISORY_LOCK_NAMESPACE = 'marketplace_financial_report_sync';

    /**
     * Статусы QUEUED (без next_retry_at — сообщение потеряно после claim) и LOADING
     * (worker убит после markLoading) без этого порога зависают навсегда: они не
     * переоткрываются claim'ом, не попадают в retry-выборку и не считаются missing.
     * Порог должен быть заметно больше lock TTL handler'а (600 c) и окна redelivery Messenger.
     */
    public const STUCK_RECLAIM_INTERVAL = 'PT2H';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceFinancialReportSyncStatus::class);
    }

    public function save(MarketplaceFinancialReportSyncStatus $syncStatus): void
    {
        $this->getEntityManager()->persist($syncStatus);
    }

    public function findByBusinessDay(
        string $companyId,
        MarketplaceType $marketplace,
        string $reportType,
        \DateTimeImmutable $businessDate,
    ): ?MarketplaceFinancialReportSyncStatus {
        Assert::uuid($companyId);

        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.businessDate = :businessDate')
            ->andWhere('s.reportType = :reportType')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('businessDate', $businessDate)
            ->setParameter('reportType', $reportType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findStatusEnumByDay(
        string $connectionId,
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $businessDate,
        string $reportType,
    ): ?FinancialReportSyncStatus {
        Assert::uuid($connectionId);
        Assert::uuid($companyId);

        $status = $this->createQueryBuilder('s')
            ->select('s.status')
            ->where('s.companyId = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.businessDate = :businessDate')
            ->andWhere('s.reportType = :reportType')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
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
        MarketplaceType $marketplace,
        string $reportType,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);

        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.reportType = :reportType')
            ->andWhere('s.businessDate BETWEEN :from AND :to')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('reportType', $reportType)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.businessDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<array{business_date: \DateTimeImmutable, mode: FinancialReportSyncMode}>
     */
    public function findRetryDueDays(
        string $companyId,
        string $connectionId,
        MarketplaceType $marketplace,
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
            ->select('s.businessDate', 's.mode')
            ->where('s.companyId = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.reportType = :reportType')
            ->andWhere('s.businessDate BETWEEN :from AND :to')
            ->andWhere('(
                (s.status = :queuedStatus AND s.nextRetryAt IS NOT NULL AND s.nextRetryAt <= :now)
                OR (s.status = :failedStatus AND s.nextRetryAt IS NOT NULL AND s.nextRetryAt <= :now)
                OR (s.status = :failedStatus AND s.nextRetryAt IS NULL AND s.lastErrorStatusCode = :rateLimitStatusCode AND s.lastErrorClass = :rateLimitErrorClass)
                OR (s.status = :queuedStatus AND s.nextRetryAt IS NULL AND s.updatedAt <= :stuckBefore)
                OR (s.status = :loadingStatus AND s.updatedAt <= :stuckBefore)
            )')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('reportType', $reportType)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('queuedStatus', FinancialReportSyncStatus::QUEUED)
            ->setParameter('failedStatus', FinancialReportSyncStatus::FAILED)
            ->setParameter('loadingStatus', FinancialReportSyncStatus::LOADING)
            ->setParameter('now', $now)
            ->setParameter('stuckBefore', $now->sub(new \DateInterval(self::STUCK_RECLAIM_INTERVAL)))
            ->setParameter('rateLimitStatusCode', 429)
            ->setParameter('rateLimitErrorClass', MarketplaceRateLimitException::class)
            ->orderBy('s.businessDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $retryItems = [];
        foreach ($rows as $row) {
            $date = $row['businessDate'] ?? null;
            if (!$date instanceof \DateTimeImmutable) {
                continue;
            }

            $mode = $row['mode'] ?? null;
            if (!$mode instanceof FinancialReportSyncMode) {
                $mode = is_string($mode) ? FinancialReportSyncMode::tryFrom($mode) : null;
            }

            $retryItems[] = [
                'business_date' => $date,
                'mode' => $mode ?? FinancialReportSyncMode::MISSING,
            ];
        }

        return $retryItems;
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
            $this->acquireBusinessDayAdvisoryLock($companyId, $marketplace, $reportType, $businessDate);

            $syncStatus = $this->createQueryBuilder('s')
                ->where('s.companyId = :companyId')
                ->andWhere('s.marketplace = :marketplace')
                ->andWhere('s.businessDate = :businessDate')
                ->andWhere('s.reportType = :reportType')
                ->setParameter('companyId', $companyId)
                ->setParameter('marketplace', $marketplace)
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
            } else {
                if (!$this->canClaimForQueue($syncStatus, $mode, $forceRefresh, $now)) {
                    $em->commit();

                    return null;
                }

                $syncStatus->updateTechnicalContext($connectionId, $apiEndpoint);
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
        $stuckBefore = $now->sub(new \DateInterval(self::STUCK_RECLAIM_INTERVAL));

        if (FinancialReportSyncStatus::LOADING === $status) {
            return $syncStatus->getUpdatedAt() <= $stuckBefore;
        }

        if (\in_array($status, [
            FinancialReportSyncStatus::RAW_LOADED,
            FinancialReportSyncStatus::PROCESSING,
        ], true)) {
            return false;
        }

        if (\in_array($status, [FinancialReportSyncStatus::QUEUED, FinancialReportSyncStatus::FAILED], true)
            && null !== $syncStatus->getNextRetryAt()
            && $syncStatus->getNextRetryAt() > $now
        ) {
            return false;
        }

        if (FinancialReportSyncStatus::QUEUED === $status) {
            if (null === $syncStatus->getNextRetryAt()) {
                return $syncStatus->getUpdatedAt() <= $stuckBefore;
            }

            return $syncStatus->getNextRetryAt() <= $now;
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

    /**
     * @return list<MarketplaceFinancialReportSyncStatus>
     */
    public function findAllByRawDocumentId(string $companyId, string $rawDocumentId): array
    {
        Assert::uuid($companyId);
        Assert::uuid($rawDocumentId);

        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.rawDocumentId = :rawDocumentId')
            ->setParameter('companyId', $companyId)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->orderBy('s.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByRawPipelineContext(
        ?string $syncStatusId,
        string $companyId,
        string $connectionId,
        MarketplaceType $marketplace,
        string $reportType,
        FinancialReportSyncMode $mode,
        \DateTimeImmutable $businessDate,
        string $rawDocumentId,
    ): ?MarketplaceFinancialReportSyncStatus {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        Assert::uuid($rawDocumentId);

        if (null !== $syncStatusId) {
            Assert::uuid($syncStatusId);

            return $this->createQueryBuilder('s')
                ->where('s.id = :syncStatusId')
                ->andWhere('s.companyId = :companyId')
                ->andWhere('s.marketplace = :marketplace')
                ->andWhere('s.reportType = :reportType')
                ->andWhere('s.mode = :mode')
                ->andWhere('s.businessDate = :businessDate')
                ->andWhere('s.rawDocumentId = :rawDocumentId')
                ->setParameter('syncStatusId', $syncStatusId)
                ->setParameter('companyId', $companyId)
                ->setParameter('marketplace', $marketplace)
                ->setParameter('reportType', $reportType)
                ->setParameter('mode', $mode)
                ->setParameter('businessDate', $businessDate)
                ->setParameter('rawDocumentId', $rawDocumentId)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        return $this->createQueryBuilder('s')
            ->where('s.companyId = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.reportType = :reportType')
            ->andWhere('s.mode = :mode')
            ->andWhere('s.businessDate = :businessDate')
            ->andWhere('s.rawDocumentId = :rawDocumentId')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('reportType', $reportType)
            ->setParameter('mode', $mode)
            ->setParameter('businessDate', $businessDate)
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

        $em = $this->getEntityManager();

        try {
            $em->beginTransaction();
            $this->acquireBusinessDayAdvisoryLock($companyId, $marketplace, $reportType, $businessDate);

            $syncStatus = $this->findByBusinessDay($companyId, $marketplace, $reportType, $businessDate);
            if ($syncStatus instanceof MarketplaceFinancialReportSyncStatus) {
                $syncStatus->updateTechnicalContext($connectionId, $apiEndpoint);
                $em->persist($syncStatus);
                $em->flush();
                $em->commit();

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

            $em->persist($syncStatus);
            $em->flush();
            $em->commit();

            return $syncStatus;
        } catch (UniqueConstraintViolationException $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            $syncStatus = $this->findByBusinessDay($companyId, $marketplace, $reportType, $businessDate);
            if ($syncStatus instanceof MarketplaceFinancialReportSyncStatus) {
                $syncStatus->updateTechnicalContext($connectionId, $apiEndpoint);
                $this->save($syncStatus);

                return $syncStatus;
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($em->getConnection()->isTransactionActive()) {
                $em->rollback();
            }

            throw $e;
        }
    }

    private function acquireBusinessDayAdvisoryLock(
        string $companyId,
        MarketplaceType $marketplace,
        string $reportType,
        \DateTimeImmutable $businessDate,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:namespace), hashtext(:business_key))',
            [
                'namespace' => self::ADVISORY_LOCK_NAMESPACE,
                'business_key' => sprintf(
                    '%s:%s:%s:%s',
                    $companyId,
                    $marketplace->value,
                    $reportType,
                    $businessDate->format('Y-m-d'),
                ),
            ],
        );
    }
}
