<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncStatus;

interface MarketplaceFinancialReportSyncStatusLookupInterface
{
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
    ): array;
}
