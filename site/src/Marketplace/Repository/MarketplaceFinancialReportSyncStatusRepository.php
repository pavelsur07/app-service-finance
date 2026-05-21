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
     * @param list<\DateTimeImmutable> $days
     *
     * @return list<\DateTimeImmutable>
     */
    public function findMissingOrRetryDueDays(
        string $connectionId,
        string $companyId,
        string $reportType,
        array $days,
        int $maxDays,
    ): array {
        Assert::uuid($connectionId);
        Assert::uuid($companyId);

        if ([] === $days || $maxDays <= 0) {
            return [];
        }

        $statuses = $this->createQueryBuilder('s')
            ->select('s.businessDate, s.status, s.nextRetryAt')
            ->where('s.connectionId = :connectionId')
            ->andWhere('s.companyId = :companyId')
            ->andWhere('s.reportType = :reportType')
            ->andWhere('s.businessDate IN (:days)')
            ->setParameter('connectionId', $connectionId)
            ->setParameter('companyId', $companyId)
            ->setParameter('reportType', $reportType)
            ->setParameter('days', $days)
            ->getQuery()
            ->getArrayResult();

        $byDay = [];
        foreach ($statuses as $row) {
            $date = $row['businessDate'];
            if ($date instanceof \DateTimeImmutable) {
                $byDay[$date->format('Y-m-d')] = [
                    'status' => $row['status'],
                    'nextRetryAt' => $row['nextRetryAt'] ?? null,
                ];
            }
        }

        $now = new \DateTimeImmutable();
        $result = [];
        foreach ($days as $day) {
            if (count($result) >= $maxDays) {
                break;
            }

            $existing = $byDay[$day->format('Y-m-d')] ?? null;
            if (null === $existing) {
                $result[] = $day;
                continue;
            }

            $status = $existing['status'];
            if (is_string($status)) {
                $status = FinancialReportSyncStatus::from($status);
            }

            if (FinancialReportSyncStatus::FAILED !== $status) {
                continue;
            }

            $nextRetryAt = $existing['nextRetryAt'];
            if (null === $nextRetryAt || ($nextRetryAt instanceof \DateTimeInterface && $nextRetryAt <= $now)) {
                $result[] = $day;
            }
        }

        return $result;
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
