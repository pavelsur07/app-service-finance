<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\Source\Ozon\OzonPerformanceReportConnector;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceCampaignNotFoundException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceReportClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class OzonPerformanceReportConnectorTest extends TestCase
{
    public function testCapabilitiesResourcesDiscoverAndUnsupportedPush(): void
    {
        $connector = $this->connector(new FakeOzonPerformanceReportClient());
        $connectionRef = Uuid::uuid7()->toString();

        self::assertSame([Capability::CAN_DISCOVER_SHOPS, Capability::CAN_PULL], $connector->capabilities());
        self::assertContains(OzonResourceType::PERFORMANCE_CAMPAIGNS, $connector->resourceTypes());
        self::assertContains(OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS, $connector->resourceTypes());
        self::assertSame($connectionRef, $connector->discoverShops(Uuid::uuid7()->toString(), $connectionRef)[0]->externalId);

        $this->expectException(UnsupportedCapabilityException::class);
        $connector->push(new PushRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: $connectionRef,
            documentType: 'doc',
            payload: [],
            idempotencyKey: 'key-1',
        ));
    }

    public function testPullCampaignCatalogStoresRawOnlyBatch(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SKU'] = [['id' => '10', 'title' => 'SKU campaign']];
        $client->campaignsByType['SEARCH_PROMO'] = [['id' => '20', 'title' => 'Search campaign']];

        $result = $this->connector($client)->pull($this->request(OzonResourceType::PERFORMANCE_CAMPAIGNS));

        self::assertNotNull($result->rawBatch);
        self::assertSame(OzonResourceType::PERFORMANCE_CAMPAIGNS, $result->rawBatch->resourceType);
        self::assertSame('performance-campaigns:2026-06-01:2026-06-07', $result->rawBatch->externalId);
        self::assertFalse($result->normalizeRawRecords);
        self::assertFalse($result->hasMore);
        self::assertSame([
            ['id' => '10', 'title' => 'SKU campaign'],
            ['id' => '20', 'title' => 'Search campaign'],
        ], $result->rawBatch->rows);
    }

    public function testPullSkuStatisticsBatchesCampaignIdsByTen(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SKU'] = array_map(
            static fn (int $id): array => ['id' => (string) $id],
            range(1, 12),
        );

        $connector = $this->connector($client);
        $first = $connector->pull($this->request(OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS));

        self::assertTrue($first->hasMore);
        self::assertSame('{"batchOffset":10}', $first->nextCursorValue);
        self::assertSame([['1', '2', '3', '4', '5', '6', '7', '8', '9', '10']], $client->skuStatsCampaignCalls);

        $second = $connector->pull($this->request(
            OzonResourceType::PERFORMANCE_SKU_PRODUCT_STATISTICS,
            cursorValue: $first->nextCursorValue,
        ));

        self::assertFalse($second->hasMore);
        self::assertNull($second->nextCursorValue);
        self::assertSame(['11', '12'], $client->skuStatsCampaignCalls[1]);
    }

    public function testPullSkuCampaignObjectsSkipsMissingCampaignAndContinues(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SKU'] = [
            ['id' => '13815400'],
            ['id' => '13815401'],
        ];
        $client->missingCampaignObjects['13815400'] = true;

        $connector = $this->connector($client);
        $first = $connector->pull($this->request(OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS));

        self::assertNotNull($first->rawBatch);
        self::assertTrue($first->hasMore);
        self::assertSame('{"campaignOffset":1}', $first->nextCursorValue);
        self::assertSame('performance-sku-objects:13815400:2026-06-01:2026-06-07', $first->rawBatch->externalId);
        self::assertSame([
            [
                '_ingestion_empty' => true,
                '_ingestion_resource' => OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
                '_ingestion_metadata' => [
                    'apiMetadata' => [
                        'endpoint' => '/api/client/campaign/13815400/objects',
                        'campaignId' => '13815400',
                        'skippedReason' => 'campaign_not_found',
                        'responseBody' => '{"error":"campaign not found"}',
                    ],
                    'campaignId' => '13815400',
                    'campaignOffset' => 0,
                    'campaignCount' => 2,
                    'skippedReason' => 'campaign_not_found',
                ],
            ],
        ], $first->rawBatch->rows);

        $second = $connector->pull($this->request(
            OzonResourceType::PERFORMANCE_SKU_CAMPAIGN_OBJECTS,
            cursorValue: $first->nextCursorValue,
        ));

        self::assertFalse($second->hasMore);
        self::assertSame([['campaign_id' => '13815401', 'sku' => 'sku-1']], $second->rawBatch?->rows);
    }

    public function testSearchPromoStatisticsGeneratesAsyncReportBeforeRawBatch(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SEARCH_PROMO'] = [['id' => 'search-1']];

        $result = $this->connector($client)->pull($this->request(OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS));

        self::assertNull($result->rawBatch);
        self::assertTrue($result->hasMore);
        self::assertSame(60, $result->continuationDelaySeconds);
        self::assertSame(
            '{"batchOffset":0,"reportType":"products","state":"poll","uuid":"report-uuid-1","pollAttempts":0,"pollStartedAt":"2026-06-27T12:00:00+00:00"}',
            $result->nextCursorValue,
        );
        self::assertSame([['products', ['search-1']]], $client->generatedReports);
    }

    public function testSearchPromoStatisticsRequeuesPollWithIncrementedAttempt(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SEARCH_PROMO'] = [['id' => 'search-1']];

        $result = $this->connector($client)->pull($this->request(
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            cursorValue: '{"batchOffset":0,"reportType":"products","state":"poll","uuid":"report-uuid-1","pollAttempts":2,"pollStartedAt":"2026-06-27T11:55:00+00:00"}',
        ));

        self::assertNull($result->rawBatch);
        self::assertTrue($result->hasMore);
        self::assertSame(60, $result->continuationDelaySeconds);
        self::assertSame(
            '{"batchOffset":0,"reportType":"products","state":"poll","uuid":"report-uuid-1","pollAttempts":3,"pollStartedAt":"2026-06-27T11:55:00+00:00"}',
            $result->nextCursorValue,
        );
    }

    public function testSearchPromoStatisticsFailsWhenReportPollingExceedsTimeout(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SEARCH_PROMO'] = [['id' => 'search-1']];

        $this->expectException(ConnectorTransientException::class);
        $this->expectExceptionMessage('Ozon Search Promo report report-uuid-1 was not ready');

        $this->connector($client)->pull($this->request(
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            cursorValue: '{"batchOffset":0,"reportType":"products","state":"poll","uuid":"report-uuid-1","pollAttempts":12,"pollStartedAt":"2026-06-27T10:59:59+00:00"}',
        ));
    }

    public function testSearchPromoStatisticsPollsAndStoresDownloadedReport(): void
    {
        $client = new FakeOzonPerformanceReportClient();
        $client->campaignsByType['SEARCH_PROMO'] = [['id' => 'search-1']];
        $client->readyReports['report-uuid-1'] = 'https://example.test/report.csv';

        $result = $this->connector($client)->pull($this->request(
            OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS,
            cursorValue: '{"batchOffset":0,"reportType":"products","state":"poll","uuid":"report-uuid-1"}',
        ));

        self::assertNotNull($result->rawBatch);
        self::assertSame(OzonResourceType::PERFORMANCE_SEARCH_PROMO_STATISTICS, $result->rawBatch->resourceType);
        self::assertSame('performance-search-promo-stats:2026-06-01:2026-06-07:products:batch:de72244d92630c60', $result->rawBatch->externalId);
        self::assertTrue($result->hasMore);
        self::assertSame('{"batchOffset":0,"reportType":"orders","state":"request"}', $result->nextCursorValue);
        self::assertSame([['sku' => 'sku-1', '_ingestion_metadata' => ['reportUuid' => 'report-uuid-1']]], $result->rawBatch->rows);
    }

    private function connector(OzonPerformanceReportClientInterface $client): OzonPerformanceReportConnector
    {
        return new OzonPerformanceReportConnector($client, new MockClock('2026-06-27 12:00:00 UTC'));
    }

    private function request(string $resourceType, ?string $cursorValue = null): PullRequest
    {
        return new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'performance-connection-1',
            shopRef: 'performance-connection-1',
            resourceType: $resourceType,
            cursorValue: $cursorValue,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-07'),
            syncJobId: Uuid::uuid7()->toString(),
        );
    }
}

final class FakeOzonPerformanceReportClient implements OzonPerformanceReportClientInterface
{
    /**
     * @var array<string, list<array<string, mixed>>>
     */
    public array $campaignsByType = [];

    /**
     * @var list<list<string>>
     */
    public array $skuStatsCampaignCalls = [];

    /**
     * @var list<array{0: string, 1: list<string>}>
     */
    public array $generatedReports = [];

    /**
     * @var array<string, string>
     */
    public array $readyReports = [];

    /**
     * @var array<string, bool>
     */
    public array $missingCampaignObjects = [];

    public function listCampaigns(string $companyId, string $connectionRef, array $advObjectTypes = []): OzonRawPage
    {
        $rows = [];
        foreach ($advObjectTypes as $type) {
            array_push($rows, ...($this->campaignsByType[$type] ?? []));
        }

        return new OzonRawPage($rows, false, metadata: ['advObjectTypes' => $advObjectTypes]);
    }

    public function fetchCampaignObjects(string $companyId, string $connectionRef, string $campaignId): OzonRawPage
    {
        if (($this->missingCampaignObjects[$campaignId] ?? false) === true) {
            throw new OzonPerformanceCampaignNotFoundException(
                $campaignId,
                sprintf('/api/client/campaign/%s/objects', $campaignId),
                '{"error":"campaign not found"}',
            );
        }

        return new OzonRawPage([['campaign_id' => $campaignId, 'sku' => 'sku-1']], false);
    }

    public function fetchSearchPromoProducts(string $companyId, string $connectionRef, string $campaignId, int $page): OzonRawPage
    {
        return new OzonRawPage([['campaign_id' => $campaignId, 'page' => $page, 'sku' => 'sku-1']], false);
    }

    public function fetchSkuProductStatistics(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
    ): OzonRawPage {
        $this->skuStatsCampaignCalls[] = $campaignIds;

        return new OzonRawPage([['campaignIds' => $campaignIds]], false);
    }

    public function generateSearchPromoReport(
        string $companyId,
        string $connectionRef,
        string $reportType,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
    ): string {
        $this->generatedReports[] = [$reportType, $campaignIds];

        return 'report-uuid-1';
    }

    public function pollReport(string $companyId, string $connectionRef, string $reportUuid): ?string
    {
        return $this->readyReports[$reportUuid] ?? null;
    }

    public function downloadReport(string $companyId, string $connectionRef, string $reportUuid, string $reportLink): OzonRawPage
    {
        return new OzonRawPage([['sku' => 'sku-1', '_ingestion_metadata' => ['reportUuid' => $reportUuid]]], false);
    }

    public function fetchExpenseStatistics(
        string $companyId,
        string $connectionRef,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): OzonRawPage {
        return new OzonRawPage([['cost' => '12.34']], false);
    }
}
