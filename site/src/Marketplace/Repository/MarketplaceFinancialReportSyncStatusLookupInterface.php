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

    /**
     * Захват зависшего RAW_LOADED / PROCESSING дня (см. реализацию). null — день не stale
     * или его уже забрал другой планировщик.
     *
     * @return array{status: MarketplaceFinancialReportSyncStatus, previous_status: FinancialReportSyncStatus, previous_updated_at: \DateTimeImmutable, reprocess: bool}|null
     */
    public function reclaimStaleProcessing(
        string $connectionId,
        string $companyId,
        MarketplaceType $marketplace,
        string $reportType,
        string $apiEndpoint,
        \DateTimeImmutable $businessDate,
        FinancialReportSyncMode $mode,
        \DateTimeImmutable $now,
    ): ?array;

    public function findByRawDocumentId(string $companyId, string $rawDocumentId): ?MarketplaceFinancialReportSyncStatus;

    /** @return list<MarketplaceFinancialReportSyncStatus> */
    public function findAllByRawDocumentId(string $companyId, string $rawDocumentId): array;

    public function findByRawPipelineContext(
        ?string $syncStatusId,
        string $companyId,
        string $connectionId,
        MarketplaceType $marketplace,
        string $reportType,
        FinancialReportSyncMode $mode,
        \DateTimeImmutable $businessDate,
        string $rawDocumentId,
    ): ?MarketplaceFinancialReportSyncStatus;

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
    ): array;

    /**
     * @return list<array{business_date: \DateTimeImmutable, mode: FinancialReportSyncMode, status?: ?FinancialReportSyncStatus}>
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
    ): array;
}
