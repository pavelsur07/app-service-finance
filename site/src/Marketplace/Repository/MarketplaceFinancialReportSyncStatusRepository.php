<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
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
    ): ?MarketplaceFinancialReportSyncStatus
    {
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
