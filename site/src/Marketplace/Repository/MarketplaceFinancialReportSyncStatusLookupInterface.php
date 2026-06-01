<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;

interface MarketplaceFinancialReportSyncStatusLookupInterface
{

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
    ): ?MarketplaceFinancialReportSyncStatus;

    public function findByRawDocumentId(string $companyId, string $rawDocumentId): ?MarketplaceFinancialReportSyncStatus;

    public function findStatusEnumByDay(
        string $connectionId,
        string $companyId,
        \DateTimeImmutable $businessDate,
        string $reportType,
    ): ?FinancialReportSyncStatus;

    /**
     * @return list<MarketplaceFinancialReportSyncStatus>
     */
    public function findStatusesForDateRange(
        string $companyId,
        string $connectionId,
        string $reportType,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array;

    /**
     * @return list<array{business_date: \DateTimeImmutable, mode: FinancialReportSyncMode}>
     */
    public function findRetryDueDays(
        string $companyId,
        string $connectionId,
        string $reportType,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now,
        int $limit,
    ): array;
}
