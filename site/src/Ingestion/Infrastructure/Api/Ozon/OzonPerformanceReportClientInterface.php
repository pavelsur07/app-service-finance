<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Api\Ozon;

interface OzonPerformanceReportClientInterface
{
    /**
     * @param list<string> $advObjectTypes
     */
    public function listCampaigns(string $companyId, string $connectionRef, array $advObjectTypes = []): OzonRawPage;

    public function fetchCampaignObjects(string $companyId, string $connectionRef, string $campaignId): OzonRawPage;

    public function fetchSearchPromoProducts(string $companyId, string $connectionRef, string $campaignId, int $page): OzonRawPage;

    /**
     * @param list<string> $campaignIds
     */
    public function fetchSkuProductStatistics(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
    ): OzonRawPage;

    /**
     * @param list<string> $campaignIds
     */
    public function generateSearchPromoReport(
        string $companyId,
        string $connectionRef,
        string $reportType,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
    ): string;

    public function pollReport(string $companyId, string $connectionRef, string $reportUuid): ?string;

    public function downloadReport(string $companyId, string $connectionRef, string $reportUuid, string $reportLink): OzonRawPage;

    public function fetchExpenseStatistics(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): OzonRawPage;
}
