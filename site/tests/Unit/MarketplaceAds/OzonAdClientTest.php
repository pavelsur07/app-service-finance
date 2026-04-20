<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonReportDownload;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OzonAdClientTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const CLIENT_ID = 'client-abc';
    private const CLIENT_SECRET = 'super-secret';

    private MarketplaceFacade&MockObject $facade;

    private OzonAdPendingReportRepository&MockObject $pendingReportRepo;

    /** @var AbstractLogger&object{records: array<int, array{level: string, message: string, context: array<string, mixed>}>} */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(MarketplaceFacade::class);
        $this->facade->method('getConnectionCredentials')
            ->with(self::COMPANY_ID, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE)
            ->willReturn(['api_key' => self::CLIENT_SECRET, 'client_id' => self::CLIENT_ID]);

        // Stub-репозиторий: персистенс pending-отчётов проверяется отдельными integration-тестами.
        // Для unit-тестов OzonAdClient достаточно no-op mock'а, чтобы методы create/updateState/
        // markFinalized не падали при реальном flush().
        $this->pendingReportRepo = $this->createMock(OzonAdPendingReportRepository::class);
        $this->pendingReportRepo->method('create')->willReturnCallback(
            static fn (string $companyId, string $ozonUuid, \DateTimeImmutable $from, \DateTimeImmutable $to, array $campaignIds, ?string $jobId): OzonAdPendingReport
                => new OzonAdPendingReport($companyId, $ozonUuid, $from, $to, $campaignIds, $jobId),
        );
        $this->pendingReportRepo->method('updateState')->willReturn(1);
        $this->pendingReportRepo->method('markFinalized')->willReturn(1);

        $this->logger = new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function findLogRecord(string $message): array
    {
        /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> $records */
        $records = $this->logger->records;
        foreach ($records as $r) {
            if ($r['message'] === $message) {
                return $r;
            }
        }
        self::fail("Log record with message \"$message\" not found. Got: ".implode(', ', array_column($records, 'message')));
    }

    // -----------------------------------------------------------------
    // a) groupBy=DATE parsing: 3 campaigns × 5 days = 15 CSV rows
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeParsesGroupByDateCsvIntoFiveDates(): void
    {
        $csv = $this->loadFixture('ozon_range_iso.csv');
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(3),
            statisticsBody: '{"UUID":"uuid-a"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-a'),
            downloadCsv: $csv,
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $result = $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
        );

        self::assertCount(5, $result, 'Expected 5 date buckets');
        self::assertSame(['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'], array_keys($result));

        foreach ($result as $date => $bucket) {
            self::assertArrayHasKey('campaigns', $bucket, "Bucket $date must have campaigns");
            self::assertCount(3, $bucket['campaigns'], "Bucket $date must have 3 campaigns");

            $ids = array_map(static fn (array $c): string => $c['campaign_id'], $bucket['campaigns']);
            sort($ids);
            self::assertSame(['111', '222', '333'], $ids);

            foreach ($bucket['campaigns'] as $campaign) {
                self::assertCount(1, $campaign['rows'], "Each campaign in $date must have exactly 1 row");
            }
        }

        // Spot-check a value: campaign 222 on 2026-03-03 has spend=22.75
        $row = $this->findCampaign($result['2026-03-03']['campaigns'], '222');
        self::assertSame('22.75', $row['rows'][0]['spend']);
        self::assertSame(220, $row['rows'][0]['views']);
        self::assertSame(12, $row['rows'][0]['clicks']);
        self::assertSame('SKU-2', $row['rows'][0]['sku']);
        self::assertSame('Campaign B', $row['campaign_name']);
    }

    // -----------------------------------------------------------------
    // b) DMY date format produces same result as ISO
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeAcceptsDmyDateFormat(): void
    {
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(3),
            statisticsBody: '{"UUID":"uuid-b"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-b'),
            downloadCsv: $this->loadFixture('ozon_range_dmy.csv'),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $resultDmy = $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
        );

        // Build a fresh client to re-parse the ISO fixture independently.
        $httpIso = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(3),
            statisticsBody: '{"UUID":"uuid-b2"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-b2'),
            downloadCsv: $this->loadFixture('ozon_range_iso.csv'),
        );
        $clientIso = new OzonAdClient($httpIso, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);
        $resultIso = $clientIso->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
        );

        self::assertSame($resultIso, $resultDmy, 'DMY parsing must produce identical output to ISO');
    }

    // -----------------------------------------------------------------
    // c) Invalid date in CSV → RuntimeException
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeThrowsOnInvalidDateInCsv(): void
    {
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-c"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-c'),
            downloadCsv: $this->loadFixture('ozon_range_invalid_date.csv'),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/не удалось распарсить дату/u');

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-01'),
        );
    }

    // -----------------------------------------------------------------
    // d) dateFrom > dateTo → InvalidArgumentException
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeRejectsInvertedRange(): void
    {
        $client = new OzonAdClient(
            new MockHttpClient([]),
            $this->facade,
            new ArrayAdapter(),
            $this->logger,
            $this->logger,
            $this->pendingReportRepo,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dateFrom.*больше.*dateTo/u');

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-10'),
            new \DateTimeImmutable('2026-03-01'),
        );
    }

    // -----------------------------------------------------------------
    // e) 63-day range → InvalidArgumentException
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeRejectsRangeLongerThan62Days(): void
    {
        $client = new OzonAdClient(
            new MockHttpClient([]),
            $this->facade,
            new ArrayAdapter(),
            $this->logger,
            $this->logger,
            $this->pendingReportRepo,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/max 62 days/');

        // 2026-01-01 .. 2026-03-04 inclusive = 63 days.
        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-03-04'),
        );
    }

    // -----------------------------------------------------------------
    // f) 401 on download → retry with fresh token
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeRetriesDownloadOnAuthExpired(): void
    {
        $csv = "date;campaign_id;campaign_name;sku;spend;views;clicks\n"
            ."2026-03-01;111;Campaign A;SKU-1;10.50;100;5\n";

        /** @var list<array{method: string, url: string, auth: ?string}> $requests */
        $requests = [];

        $responses = $this->scriptedResponsesWithRecording($requests, [
            // 1) first token fetch
            new MockResponse($this->tokenBody('TKN-OLD')),
            // 2) GET /campaign
            new MockResponse($this->campaignListBody(1)),
            // 3) POST /statistics
            new MockResponse('{"UUID":"uuid-f"}'),
            // 4) GET /statistics/{uuid} state
            new MockResponse($this->stateReadyBody('/api/client/statistics/report?UUID=uuid-f')),
            // 5) GET /report → 401 (auth expired)
            new MockResponse('', ['http_code' => 401]),
            // 6) second token fetch (forced refresh)
            new MockResponse($this->tokenBody('TKN-NEW')),
            // 7) GET /report → 200 CSV
            new MockResponse($csv),
        ]);

        $http = new MockHttpClient($responses);
        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $result = $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-01'),
        );

        self::assertCount(1, $result);
        self::assertArrayHasKey('2026-03-01', $result);
        self::assertCount(1, $result['2026-03-01']['campaigns']);
        self::assertSame('111', $result['2026-03-01']['campaigns'][0]['campaign_id']);

        // Verify the 7 HTTP requests happened in expected order with expected auth.
        self::assertCount(7, $requests, 'Expected exactly 7 HTTP requests (token, list, stats, state, 401 download, new token, retry download)');

        self::assertStringContainsString('/api/client/token', $requests[0]['url']);
        self::assertStringContainsString('/api/client/campaign', $requests[1]['url']);
        self::assertStringContainsString('/api/client/statistics', $requests[2]['url']);
        self::assertStringContainsString('/api/client/statistics/', $requests[3]['url']);
        self::assertStringContainsString('/api/client/statistics/report', $requests[4]['url']);
        self::assertStringContainsString('/api/client/token', $requests[5]['url']);
        self::assertStringContainsString('/api/client/statistics/report', $requests[6]['url']);

        // First download used old token; retry used new token.
        self::assertSame('Bearer TKN-OLD', $requests[4]['auth']);
        self::assertSame('Bearer TKN-NEW', $requests[6]['auth']);
    }

    // -----------------------------------------------------------------
    // Regression: localized ("Дата") header must be found by findDateField
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeHandlesCyrillicHeader(): void
    {
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(2),
            statisticsBody: '{"UUID":"uuid-cyr"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-cyr'),
            downloadCsv: $this->loadFixture('ozon_range_cyrillic_header.csv'),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $result = $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-02'),
        );

        self::assertSame(['2026-03-01', '2026-03-02'], array_keys($result));
        self::assertCount(1, $result['2026-03-01']['campaigns']);
        self::assertSame('111', $result['2026-03-01']['campaigns'][0]['campaign_id']);
        self::assertSame('SKU-1', $result['2026-03-01']['campaigns'][0]['rows'][0]['sku']);
    }

    // -----------------------------------------------------------------
    // Instrumentation: success log "Ozon ad statistics fetched" carries
    // company_id/date_from/date_to/chunk_days/campaigns_count/rows_count/
    // duration_ms/poll_attempts
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeEmitsInstrumentationLogOnSuccess(): void
    {
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(3),
            statisticsBody: '{"UUID":"uuid-instr"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-instr'),
            downloadCsv: $this->loadFixture('ozon_range_iso.csv'),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
        );

        $record = $this->findLogRecord('Ozon ad statistics fetched');
        self::assertSame('info', $record['level']);

        $ctx = $record['context'];
        self::assertSame(self::COMPANY_ID, $ctx['company_id']);
        self::assertSame('2026-03-01', $ctx['date_from']);
        self::assertSame('2026-03-05', $ctx['date_to']);
        self::assertSame(5, $ctx['chunk_days']);
        self::assertSame(3, $ctx['campaigns_count']);
        self::assertSame(15, $ctx['rows_count'], '3 campaigns × 5 days = 15 rows');
        self::assertSame(1, $ctx['poll_attempts'], 'State=OK on first poll → 1 attempt');
        self::assertIsInt($ctx['duration_ms']);
        self::assertGreaterThanOrEqual(0, $ctx['duration_ms']);
        self::assertArrayNotHasKey('error_class', $ctx);
    }

    // -----------------------------------------------------------------
    // Instrumentation: error log "Ozon ad statistics fetch failed" carries
    // same context + error_class/error_message, and the original exception
    // is rethrown
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangeEmitsInstrumentationLogOnError(): void
    {
        // Инвертированный диапазон — падает в assertValidRange до любого HTTP-запроса.
        $client = new OzonAdClient(
            new MockHttpClient([]),
            $this->facade,
            new ArrayAdapter(),
            $this->logger,
            $this->logger,
            $this->pendingReportRepo,
        );

        try {
            $client->fetchAdStatisticsRange(
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-03-10'),
                new \DateTimeImmutable('2026-03-01'),
            );
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // Expected.
        }

        $record = $this->findLogRecord('Ozon ad statistics fetch failed');
        self::assertSame('error', $record['level']);

        $ctx = $record['context'];
        self::assertSame(self::COMPANY_ID, $ctx['company_id']);
        self::assertSame('2026-03-10', $ctx['date_from']);
        self::assertSame('2026-03-01', $ctx['date_to']);
        // chunkDays считается через diff->days — у diff() знак всегда положительный
        // (invert=1 флагом), поэтому логируем число суток между датами + 1.
        self::assertSame(10, $ctx['chunk_days']);
        self::assertSame(0, $ctx['campaigns_count']);
        self::assertSame(0, $ctx['rows_count']);
        self::assertSame(0, $ctx['poll_attempts']);
        self::assertIsInt($ctx['duration_ms']);
        self::assertSame(\InvalidArgumentException::class, $ctx['error_class']);
        self::assertStringContainsString('больше', $ctx['error_message']);
    }

    // -----------------------------------------------------------------
    // Error payload stringify: scalar error → сообщение содержит строку как есть
    // -----------------------------------------------------------------
    public function testPollReportWithStringErrorIncludesItVerbatim(): void
    {
        $http = $this->buildHttpClientForError(
            tokenBody: $this->tokenBody('TKN-E1'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-err-1"}',
            stateBody: json_encode(['state' => 'ERROR', 'error' => 'нет прав'], JSON_THROW_ON_ERROR),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        try {
            $client->fetchAdStatisticsRange(
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-01'),
            );
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('uuid-err-1', $msg);
            self::assertStringContainsString('ERROR', $msg);
            self::assertStringContainsString('нет прав', $msg);
            self::assertStringNotContainsString('Array', $msg);
        }
    }

    // -----------------------------------------------------------------
    // Error payload stringify: object error → JSON с UNESCAPED_UNICODE/SLASHES
    // -----------------------------------------------------------------
    public function testPollReportWithObjectErrorSerializesAsJson(): void
    {
        $errorPayload = [
            'code' => 'FORBIDDEN',
            'message' => 'Нет доступа к /api/client/statistics',
            'details' => ['scope' => 'advertising'],
        ];

        $http = $this->buildHttpClientForError(
            tokenBody: $this->tokenBody('TKN-E2'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-err-2"}',
            stateBody: json_encode(['state' => 'ERROR', 'error' => $errorPayload], JSON_THROW_ON_ERROR),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        try {
            $client->fetchAdStatisticsRange(
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-01'),
            );
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringNotContainsString('Array', $msg);
            self::assertStringContainsString('FORBIDDEN', $msg);
            // UNESCAPED_UNICODE — кириллица НЕ как \u0420\u0435\u0433...
            self::assertStringContainsString('Нет доступа', $msg);
            self::assertStringNotContainsString('\\u04', $msg);
            // UNESCAPED_SLASHES — /api/client/statistics без \/
            self::assertStringContainsString('/api/client/statistics', $msg);
            self::assertStringNotContainsString('\\/', $msg);
        }
    }

    // -----------------------------------------------------------------
    // Error payload stringify: list-array error → JSON-массив
    // -----------------------------------------------------------------
    public function testPollReportWithArrayErrorSerializesAsJson(): void
    {
        $errorPayload = ['Первая причина', 'Вторая причина'];

        $http = $this->buildHttpClientForError(
            tokenBody: $this->tokenBody('TKN-E3'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-err-3"}',
            stateBody: json_encode(['state' => 'ERROR', 'error' => $errorPayload], JSON_THROW_ON_ERROR),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        try {
            $client->fetchAdStatisticsRange(
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-01'),
            );
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringNotContainsString('Array', $msg);
            self::assertStringContainsString('["Первая причина","Вторая причина"]', $msg);
        }
    }

    // -----------------------------------------------------------------
    // HTTP non-2xx body propagation: RuntimeException message должен
    // включать и код, и тело ответа (для диагностики 400 от /statistics).
    // -----------------------------------------------------------------
    public function testAuthorizedRequestIncludesResponseBodyInExceptionOnHttp400(): void
    {
        $http = new MockHttpClient([
            // 1) token
            new MockResponse($this->tokenBody('TKN-400')),
            // 2) GET /campaign
            new MockResponse($this->campaignListBody(1)),
            // 3) POST /statistics → 400 с диагностическим телом
            new MockResponse('{"error":"invalid campaign id"}', ['http_code' => 400]),
        ]);

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        try {
            $client->fetchAdStatisticsRange(
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-01'),
            );
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('HTTP 400', $msg);
            self::assertStringContainsString('invalid campaign id', $msg);
        }
    }

    // -----------------------------------------------------------------
    // h) Campaign filter: old range (dateTo > 14 days ago) — keep all campaigns
    //    including ARCHIVED / INACTIVE (backfill). Log marked backfillMode=true.
    // -----------------------------------------------------------------
    public function testFilterCampaignsBackfillModeKeepsAllCampaigns(): void
    {
        $campaignList = $this->campaignListBodyWithStates([
            ['id' => '111', 'title' => 'Running',  'state' => 'CAMPAIGN_STATE_RUNNING'],
            ['id' => '222', 'title' => 'Archived', 'state' => 'CAMPAIGN_STATE_ARCHIVED'],
            ['id' => '333', 'title' => 'Inactive', 'state' => 'CAMPAIGN_STATE_INACTIVE'],
            ['id' => '444', 'title' => 'NoState',  'state' => ''],
        ]);

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-BF'),
            campaignListBody: $campaignList,
            statisticsBody: '{"UUID":"uuid-bf"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-bf'),
            downloadCsv: "date;campaign_id;campaign_name;sku;spend;views;clicks\n",
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        // Far-past range — guaranteed older than 14 days regardless of run date.
        $dateFrom = (new \DateTimeImmutable('today'))->modify('-60 days');
        $dateTo = (new \DateTimeImmutable('today'))->modify('-30 days');

        $client->fetchAdStatisticsRange(self::COMPANY_ID, $dateFrom, $dateTo);

        $record = $this->findLogRecord('Campaigns filtered by state');
        self::assertSame('info', $record['level']);
        self::assertSame(4, $record['context']['totalCampaigns']);
        self::assertSame(4, $record['context']['filteredCampaigns']);
        self::assertSame([], $record['context']['skippedStates']);
        self::assertTrue($record['context']['backfillMode']);
    }

    // -----------------------------------------------------------------
    // i) Campaign filter: recent range (dateTo within 14 days) — drop
    //    ARCHIVED / INACTIVE, keep RUNNING / PLANNED / STOPPED.
    // -----------------------------------------------------------------
    public function testFilterCampaignsRecentRangeKeepsOnlyActiveStates(): void
    {
        $campaignList = $this->campaignListBodyWithStates([
            ['id' => '111', 'title' => 'Running',  'state' => 'CAMPAIGN_STATE_RUNNING'],
            ['id' => '222', 'title' => 'Planned',  'state' => 'CAMPAIGN_STATE_PLANNED'],
            ['id' => '333', 'title' => 'Stopped',  'state' => 'CAMPAIGN_STATE_STOPPED'],
            ['id' => '444', 'title' => 'Archived', 'state' => 'CAMPAIGN_STATE_ARCHIVED'],
            ['id' => '555', 'title' => 'Inactive', 'state' => 'CAMPAIGN_STATE_INACTIVE'],
        ]);

        $requests = [];
        $responses = $this->scriptedResponsesWithRecording($requests, [
            new MockResponse($this->tokenBody('TKN-R')),
            new MockResponse($campaignList),
            new MockResponse('{"UUID":"uuid-r"}'),
            new MockResponse($this->stateReadyBody('/api/client/statistics/report?UUID=uuid-r')),
            new MockResponse("date;campaign_id;campaign_name;sku;spend;views;clicks\n"),
        ]);

        $http = new MockHttpClient($responses);
        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        // Recent range — dateTo is within 14-day window from today.
        $dateFrom = (new \DateTimeImmutable('today'))->modify('-5 days');
        $dateTo = (new \DateTimeImmutable('today'))->modify('-1 day');

        $client->fetchAdStatisticsRange(self::COMPANY_ID, $dateFrom, $dateTo);

        $record = $this->findLogRecord('Campaigns filtered by state');
        self::assertSame(5, $record['context']['totalCampaigns']);
        self::assertSame(3, $record['context']['filteredCampaigns']);
        self::assertFalse($record['context']['backfillMode']);
        self::assertSame(
            ['CAMPAIGN_STATE_ARCHIVED' => 1, 'CAMPAIGN_STATE_INACTIVE' => 1],
            $record['context']['skippedStates'],
        );

        // Exactly 5 HTTP requests (one stats batch of 3 filtered campaigns).
        self::assertCount(5, $requests, 'Expected 5 HTTP requests: token, /campaign, /statistics, /state, /download');
    }

    // -----------------------------------------------------------------
    // j.1) Campaign filter: mixed range (starts >14 days ago, ends today) →
    //      treated as backfill, ARCHIVED/INACTIVE kept. Guards against dropping
    //      campaigns that were active in the older part of a long chunk.
    // -----------------------------------------------------------------
    public function testFilterCampaignsLongChunkEndingTodayIsBackfillMode(): void
    {
        $campaignList = $this->campaignListBodyWithStates([
            ['id' => '111', 'title' => 'Running',  'state' => 'CAMPAIGN_STATE_RUNNING'],
            ['id' => '222', 'title' => 'Archived', 'state' => 'CAMPAIGN_STATE_ARCHIVED'],
            ['id' => '333', 'title' => 'Inactive', 'state' => 'CAMPAIGN_STATE_INACTIVE'],
        ]);

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-LC'),
            campaignListBody: $campaignList,
            statisticsBody: '{"UUID":"uuid-lc"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-lc'),
            downloadCsv: "date;campaign_id;campaign_name;sku;spend;views;clicks\n",
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        // dateFrom 30 days ago (< cutoff), dateTo today (>> cutoff).
        // Must behave as backfill: all 3 campaigns kept despite dateTo being recent.
        $dateFrom = (new \DateTimeImmutable('today'))->modify('-30 days');
        $dateTo = new \DateTimeImmutable('today');

        $client->fetchAdStatisticsRange(self::COMPANY_ID, $dateFrom, $dateTo);

        $record = $this->findLogRecord('Campaigns filtered by state');
        self::assertTrue(
            $record['context']['backfillMode'],
            'Chunk starting 30 days ago must be backfill even if it ends today',
        );
        self::assertSame(3, $record['context']['totalCampaigns']);
        self::assertSame(3, $record['context']['filteredCampaigns']);
        self::assertSame([], $record['context']['skippedStates']);
    }

    // -----------------------------------------------------------------
    // j) Campaign filter: recent range, empty state → campaign preserved.
    // -----------------------------------------------------------------
    public function testFilterCampaignsRecentRangeKeepsEmptyState(): void
    {
        $campaignList = $this->campaignListBodyWithStates([
            ['id' => '111', 'title' => 'NoState',  'state' => ''],
            ['id' => '222', 'title' => 'Archived', 'state' => 'CAMPAIGN_STATE_ARCHIVED'],
        ]);

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-E'),
            campaignListBody: $campaignList,
            statisticsBody: '{"UUID":"uuid-e"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-e'),
            downloadCsv: "date;campaign_id;campaign_name;sku;spend;views;clicks\n",
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $dateFrom = (new \DateTimeImmutable('today'))->modify('-3 days');
        $dateTo = (new \DateTimeImmutable('today'))->modify('-1 day');

        $client->fetchAdStatisticsRange(self::COMPANY_ID, $dateFrom, $dateTo);

        $record = $this->findLogRecord('Campaigns filtered by state');
        self::assertFalse($record['context']['backfillMode']);
        self::assertSame(2, $record['context']['totalCampaigns']);
        self::assertSame(1, $record['context']['filteredCampaigns']);
        self::assertSame(['CAMPAIGN_STATE_ARCHIVED' => 1], $record['context']['skippedStates']);
    }

    // -----------------------------------------------------------------
    // g) Backward-compat: fetchAdStatistics($companyId, $date) still works
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsLegacyContractRemainsStable(): void
    {
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-1'),
            campaignListBody: $this->campaignListBody(2),
            statisticsBody: '{"UUID":"uuid-g"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-g'),
            downloadCsv: $this->loadFixture('ozon_single_day_legacy.csv'),
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $json = $client->fetchAdStatistics(self::COMPANY_ID, new \DateTimeImmutable('2026-03-01'));

        self::assertJson($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('rows', $decoded);
        self::assertCount(2, $decoded['rows']);

        // Shape check — identical to the pre-range contract consumed by OzonAdRawDataParser.
        $first = $decoded['rows'][0];
        self::assertSame(
            ['campaign_id', 'campaign_name', 'sku', 'spend', 'views', 'clicks'],
            array_keys($first),
        );
        self::assertSame('111', $first['campaign_id']);
        self::assertSame('Campaign A', $first['campaign_name']);
        self::assertSame('SKU-1', $first['sku']);
        self::assertSame(10.5, $first['spend']);
        self::assertSame(100, $first['views']);
        self::assertSame(5, $first['clicks']);
    }

    // -----------------------------------------------------------------
    // Bronze: ZIP detection + extraction
    // -----------------------------------------------------------------
    public function testDownloadReportDetectsZipAndExtractsCsv(): void
    {
        $csvInsideZip = $this->loadFixture('ozon_range_iso.csv');
        $zipBytes = $this->buildZipBytes(['report.csv' => $csvInsideZip]);

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-Z1'),
            campaignListBody: $this->campaignListBody(3),
            statisticsBody: '{"UUID":"uuid-zip-1"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-zip-1'),
            downloadCsv: $zipBytes,
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $result = $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
        );

        // Парсинг распакованного CSV обязан дать те же 5 дат × 3 кампании, что и для plain-CSV.
        self::assertCount(5, $result);
        self::assertSame(['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'], array_keys($result));
        self::assertCount(3, $result['2026-03-01']['campaigns']);

        $downloads = $client->getLastChunkDownloads();
        self::assertCount(1, $downloads);
        self::assertTrue($downloads[0]->wasZip);
        self::assertSame(1, $downloads[0]->filesInZip);
        self::assertSame($zipBytes, $downloads[0]->rawBytes);
        self::assertSame('uuid-zip-1', $downloads[0]->reportUuid);

        $record = $this->findLogRecord('Ozon report downloaded');
        self::assertTrue($record['context']['was_zip']);
        self::assertSame(1, $record['context']['files_in_zip']);
        self::assertSame(strlen($zipBytes), $record['context']['size_bytes']);
        self::assertSame(strlen($csvInsideZip), $record['context']['csv_size_bytes']);
    }

    public function testDownloadReportHandlesMultipleCsvInZip(): void
    {
        // Первая часть CSV — заголовок + 2 строки первых двух дат,
        // вторая часть — заголовок + 1 строка третьей даты.
        // Ozon в мульти-файловом ZIP дублирует header в каждой CSV-части:
        // без удаления повторных заголовков parseDateField() упадёт на
        // "date" (строка header2, попавшая в поток data-строк).
        $part1 = "date;campaign_id;campaign_name;sku;spend;views;clicks\n"
            ."2026-03-01;111;Campaign A;SKU-1;10.50;100;5\n"
            ."2026-03-02;111;Campaign A;SKU-1;11.50;110;6\n";
        $part2 = "date;campaign_id;campaign_name;sku;spend;views;clicks\n"
            ."2026-03-03;111;Campaign A;SKU-1;12.50;120;7\n";

        $zipBytes = $this->buildZipBytes([
            'report-part-1.csv' => $part1,
            'manifest.json' => '{"ignored":true}',
            'report-part-2.csv' => $part2,
        ]);

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-Z2'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-zip-multi"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-zip-multi'),
            downloadCsv: $zipBytes,
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $result = $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-03'),
        );

        // Полный прогон парсера: данные обеих частей должны объединиться в
        // три даты × одна кампания. Без фикса «drop repeated headers»
        // fetchAdStatisticsRange выкинул бы RuntimeException на parseDateField('date').
        self::assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_keys($result));
        foreach ($result as $date => $payload) {
            self::assertCount(1, $payload['campaigns'], "дата $date должна содержать 1 кампанию");
            self::assertSame('111', $payload['campaigns'][0]['campaign_id']);
            self::assertCount(1, $payload['campaigns'][0]['rows'], "дата $date должна содержать 1 строку SKU");
        }

        $downloads = $client->getLastChunkDownloads();
        self::assertCount(1, $downloads);
        self::assertTrue($downloads[0]->wasZip);
        // filesInZip считает ВСЕ файлы в архиве включая manifest.json; CSV-only
        // отфильтровывается уже при склейке csvContent.
        self::assertSame(3, $downloads[0]->filesInZip);
        self::assertStringContainsString('2026-03-01;111', $downloads[0]->csvContent);
        self::assertStringContainsString('2026-03-03;111', $downloads[0]->csvContent);
        // manifest.json не должен попасть в csvContent.
        self::assertStringNotContainsString('"ignored"', $downloads[0]->csvContent);
        // Заголовок "date;campaign_id;..." должен присутствовать в csvContent
        // ровно один раз — у первой CSV-части. Повторы отрезаются при склейке.
        self::assertSame(
            1,
            substr_count($downloads[0]->csvContent, 'date;campaign_id;campaign_name;sku;spend;views;clicks'),
            'заголовок должен встречаться в csvContent ровно один раз после объединения частей',
        );
    }

    public function testDownloadReportThrowsOnCorruptedZip(): void
    {
        // Обрезанный ZIP: magic bytes PK\x03\x04 присутствуют (→ wasZip=true),
        // но остальное — мусор, ZipArchive::open() вернёт код ошибки.
        $corrupted = "PK\x03\x04".str_repeat("\x00", 16).'garbage';

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-Z3'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-zip-bad"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-zip-bad'),
            downloadCsv: $corrupted,
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        try {
            $client->fetchAdStatisticsRange(
                self::COMPANY_ID,
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-01'),
            );
            self::fail('Expected RuntimeException for corrupted ZIP');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('uuid-zip-bad', $msg);
            self::assertStringContainsString('ZIP', $msg);
        }
    }

    public function testDownloadReportPassesPlainCsvUnchanged(): void
    {
        $csv = $this->loadFixture('ozon_range_iso.csv');

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-Z4'),
            campaignListBody: $this->campaignListBody(3),
            statisticsBody: '{"UUID":"uuid-plain"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-plain'),
            downloadCsv: $csv,
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
        );

        $downloads = $client->getLastChunkDownloads();
        self::assertCount(1, $downloads);
        self::assertFalse($downloads[0]->wasZip);
        self::assertSame(0, $downloads[0]->filesInZip);
        // Для plain-CSV rawBytes и csvContent содержательно совпадают.
        self::assertSame($csv, $downloads[0]->rawBytes);
        self::assertSame($csv, $downloads[0]->csvContent);
        self::assertSame('uuid-plain', $downloads[0]->reportUuid);

        $record = $this->findLogRecord('Ozon report downloaded');
        self::assertFalse($record['context']['was_zip']);
        self::assertNull($record['context']['files_in_zip']);
    }

    public function testRawBytesPreservedInResult(): void
    {
        // Инвариант: для wasZip=true rawBytes — исходный ZIP (непригодный
        // для fgetcsv), csvContent — распакованный CSV. Bronze-хранилище
        // обязано получить ИМЕННО rawBytes, иначе replay-парсинг сломается.
        $csv = "date;campaign_id;campaign_name;sku;spend;views;clicks\n"
            ."2026-03-01;111;Campaign A;SKU-1;1.00;10;1\n";
        $zipBytes = $this->buildZipBytes(['report.csv' => $csv]);

        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-Z5'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-raw-zip"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-raw-zip'),
            downloadCsv: $zipBytes,
        );

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $this->pendingReportRepo);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-01'),
        );

        $downloads = $client->getLastChunkDownloads();
        self::assertCount(1, $downloads);
        /** @var OzonReportDownload $download */
        $download = $downloads[0];

        self::assertTrue($download->wasZip);
        // rawBytes — PK-magic, НЕ начинается с CSV-заголовка.
        self::assertSame("PK\x03\x04", substr($download->rawBytes, 0, 4));
        self::assertStringStartsNotWith('date;', $download->rawBytes);

        // csvContent — уже распакованный CSV, начинается с заголовка.
        self::assertStringStartsWith('date;campaign_id', $download->csvContent);
        self::assertNotSame($download->rawBytes, $download->csvContent);

        // sha256 и sizeBytes считаются по rawBytes (а не csvContent) —
        // bronze-хранилище и UI-диагностика работают с исходным файлом.
        self::assertSame(hash('sha256', $zipBytes), $download->sha256);
        self::assertSame(strlen($zipBytes), $download->sizeBytes);
    }

    // -----------------------------------------------------------------
    // UUID persistence — create() обязан быть вызван ДО любого pollReport'а
    // -----------------------------------------------------------------
    public function testFetchAdStatisticsRangePersistsUuidBeforePolling(): void
    {
        $csv = $this->loadFixture('ozon_range_iso.csv');
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-P1'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-persist-1"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-persist-1'),
            downloadCsv: $csv,
        );

        // $events фиксирует порядок вызовов create() / updateState() / markFinalized():
        // create() обязан произойти СТРОГО до первого updateState(). Без этого
        // invariant'а поллинг мог бы начать писать по несуществующему ozon_uuid.
        /** @var list<string> $events */
        $events = [];

        $repo = $this->createMock(OzonAdPendingReportRepository::class);
        $repo->expects(self::once())
            ->method('create')
            ->with(
                self::equalTo(self::COMPANY_ID),
                self::equalTo('uuid-persist-1'),
                self::callback(static fn (\DateTimeImmutable $df): bool => '2026-03-01' === $df->format('Y-m-d')),
                self::callback(static fn (\DateTimeImmutable $dt): bool => '2026-03-05' === $dt->format('Y-m-d')),
                self::equalTo(['111']),
                self::equalTo('aaaaaaaa-aaaa-aaaa-aaaa-000000000001'),
            )
            ->willReturnCallback(
                static function (string $companyId, string $ozonUuid, \DateTimeImmutable $from, \DateTimeImmutable $to, array $campaignIds, ?string $jobId) use (&$events): OzonAdPendingReport {
                    $events[] = 'create';

                    return new OzonAdPendingReport($companyId, $ozonUuid, $from, $to, $campaignIds, $jobId);
                },
            );
        $repo->method('updateState')->willReturnCallback(static function () use (&$events): int {
            $events[] = 'updateState';

            return 1;
        });
        // На успешном pollReport() ожидаем markFinalized(OK).
        $repo->expects(self::once())
            ->method('markFinalized')
            ->with(self::COMPANY_ID, 'uuid-persist-1', 'OK', null)
            ->willReturnCallback(static function () use (&$events): int {
                $events[] = 'markFinalized';

                return 1;
            });

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $repo);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-05'),
            'aaaaaaaa-aaaa-aaaa-aaaa-000000000001',
        );

        // Ordering invariant: первая запись в $events — create; все updateState
        // идут ПОСЛЕ create; markFinalized — самым последним.
        self::assertNotEmpty($events);
        self::assertSame('create', $events[0], 'create() обязан быть вызван до первого updateState()');
        $firstUpdateIdx = array_search('updateState', $events, true);
        self::assertIsInt($firstUpdateIdx, 'updateState() должен быть вызван хотя бы раз');
        self::assertGreaterThan(0, $firstUpdateIdx, 'updateState() не может опережать create()');
        self::assertSame('markFinalized', end($events), 'markFinalized() обязан быть последним вызовом');
    }

    // -----------------------------------------------------------------
    // pollReport: КАЖДАЯ итерация вызывает updateState + пишет "Ozon poll iteration"
    // -----------------------------------------------------------------
    public function testPollReportLogsAndPersistsStateEveryIteration(): void
    {
        // Полная hippy-path цепочка, state=OK на первой итерации — одна updateState(..., 'OK', ...).
        $http = $this->buildHttpClientForRange(
            tokenBody: $this->tokenBody('TKN-L1'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-log-1"}',
            stateBody: $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-log-1'),
            downloadCsv: "date;campaign_id;campaign_name;sku;spend;views;clicks\n2026-03-01;111;Campaign A;SKU-1;10;1;1\n",
        );

        $updateStateCalls = [];
        $repo = $this->createMock(OzonAdPendingReportRepository::class);
        $repo->method('create')->willReturnCallback(
            static fn (string $companyId, string $ozonUuid, \DateTimeImmutable $from, \DateTimeImmutable $to, array $campaignIds, ?string $jobId): OzonAdPendingReport
                => new OzonAdPendingReport($companyId, $ozonUuid, $from, $to, $campaignIds, $jobId),
        );
        $repo->expects(self::atLeastOnce())
            ->method('updateState')
            ->willReturnCallback(function (string $companyId, string $uuid, string $state, \DateTimeImmutable $now, int $attempt, ?\DateTimeImmutable $firstNonPendingAt = null) use (&$updateStateCalls): int {
                $updateStateCalls[] = [
                    'companyId' => $companyId,
                    'uuid' => $uuid,
                    'state' => $state,
                    'attempt' => $attempt,
                    'firstNonPendingAt' => $firstNonPendingAt,
                ];

                return 1;
            });
        $repo->expects(self::once())->method('markFinalized')->with(self::COMPANY_ID, 'uuid-log-1', 'OK', null);

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $repo);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-01'),
            'aaaaaaaa-aaaa-aaaa-aaaa-000000000002',
        );

        self::assertCount(1, $updateStateCalls, 'OK на первой итерации → ровно один updateState()');
        self::assertSame(self::COMPANY_ID, $updateStateCalls[0]['companyId']);
        self::assertSame('uuid-log-1', $updateStateCalls[0]['uuid']);
        self::assertSame('OK', $updateStateCalls[0]['state']);
        self::assertSame(1, $updateStateCalls[0]['attempt']);
        // state='OK' ≠ 'NOT_STARTED' → firstNonPendingAt должен быть передан (not null).
        self::assertNotNull($updateStateCalls[0]['firstNonPendingAt']);

        // Проверяем, что "Ozon poll iteration" лог выписан с контекстом.
        $record = $this->findLogRecord('Ozon poll iteration');
        self::assertSame('uuid-log-1', $record['context']['reportUuid']);
        self::assertSame(1, $record['context']['attempt']);
        self::assertSame('OK', $record['context']['state']);
    }

    // -----------------------------------------------------------------
    // pollReport: NOT_STARTED → IN_PROGRESS → OK.
    // Проверяет:
    //  • updateState() вызван для каждой из трёх итераций с корректным state/attempt;
    //  • firstNonPendingAt == null на iter1 (NOT_STARTED), не-null на iter2/iter3;
    //  • ровно одна finalization (OK) в самом конце.
    //
    // pollReport() делает sleep(POLL_INTERVAL_SECONDS=5) между итерациями,
    // поэтому тест занимает ~10 секунд реального времени. Это сознательный
    // trade-off: альтернатива — refactor под инжектируемый sleeper, что
    // ломает сигнатуру и плодит моки.
    // -----------------------------------------------------------------
    public function testPollReportCapturesFirstNonPendingAtOnceAcrossIterations(): void
    {
        $statePending = json_encode(['state' => 'NOT_STARTED'], JSON_THROW_ON_ERROR);
        $stateInProgress = json_encode(['state' => 'IN_PROGRESS'], JSON_THROW_ON_ERROR);
        $stateReady = $this->stateReadyBody('/api/client/statistics/report?UUID=uuid-multi');

        $http = new MockHttpClient([
            new MockResponse($this->tokenBody('TKN-MULTI')),
            new MockResponse($this->campaignListBody(1)),
            new MockResponse('{"UUID":"uuid-multi"}'),
            // Три последовательных GET /statistics/{uuid}: NOT_STARTED → IN_PROGRESS → OK.
            new MockResponse($statePending),
            new MockResponse($stateInProgress),
            new MockResponse($stateReady),
            // download — однострочный CSV, чтобы не влиять на парсинг.
            new MockResponse("date;campaign_id;campaign_name;sku;spend;views;clicks\n2026-03-01;111;Campaign A;SKU-1;1;1;1\n"),
        ]);

        /** @var list<array{companyId: string, uuid: string, state: string, attempt: int, firstNonPendingAt: ?\DateTimeImmutable}> $calls */
        $calls = [];

        $repo = $this->createMock(OzonAdPendingReportRepository::class);
        $repo->method('create')->willReturnCallback(
            static fn (string $companyId, string $ozonUuid, \DateTimeImmutable $from, \DateTimeImmutable $to, array $campaignIds, ?string $jobId): OzonAdPendingReport
                => new OzonAdPendingReport($companyId, $ozonUuid, $from, $to, $campaignIds, $jobId),
        );
        $repo->expects(self::exactly(3))
            ->method('updateState')
            ->willReturnCallback(function (string $companyId, string $uuid, string $state, \DateTimeImmutable $now, int $attempt, ?\DateTimeImmutable $firstNonPendingAt = null) use (&$calls): int {
                $calls[] = [
                    'companyId' => $companyId,
                    'uuid' => $uuid,
                    'state' => $state,
                    'attempt' => $attempt,
                    'firstNonPendingAt' => $firstNonPendingAt,
                ];

                return 1;
            });
        $repo->expects(self::once())
            ->method('markFinalized')
            ->with(self::COMPANY_ID, 'uuid-multi', 'OK', null)
            ->willReturn(1);

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $repo);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-01'),
            'aaaaaaaa-aaaa-aaaa-aaaa-000000000004',
        );

        self::assertCount(3, $calls);

        self::assertSame(self::COMPANY_ID, $calls[0]['companyId']);
        self::assertSame('uuid-multi', $calls[0]['uuid']);
        self::assertSame('NOT_STARTED', $calls[0]['state']);
        self::assertSame(1, $calls[0]['attempt']);
        self::assertNull($calls[0]['firstNonPendingAt'], 'NOT_STARTED iteration must not capture firstNonPendingAt');

        self::assertSame('IN_PROGRESS', $calls[1]['state']);
        self::assertSame(2, $calls[1]['attempt']);
        self::assertNotNull($calls[1]['firstNonPendingAt'], 'IN_PROGRESS iteration must capture firstNonPendingAt');

        self::assertSame('OK', $calls[2]['state']);
        self::assertSame(3, $calls[2]['attempt']);
        // OK-итерация тоже передаёт firstNonPendingAt — COALESCE в Repository
        // обеспечивает, что SQL-level значение не перезапишется (integration-тест
        // testUpdateStateAdvancesAttemptAndTimestamps проверяет именно этот
        // COALESCE-инвариант; здесь мы только фиксируем, что клиент передаёт
        // не-null, чтобы COALESCE имел шанс сработать).
        self::assertNotNull($calls[2]['firstNonPendingAt']);

        // Каждая итерация пишет "Ozon poll iteration" в info-канал.
        $iterationLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'Ozon poll iteration' === $r['message'],
        ));
        self::assertCount(3, $iterationLogs);
        self::assertSame('NOT_STARTED', $iterationLogs[0]['context']['state']);
        self::assertSame('IN_PROGRESS', $iterationLogs[1]['context']['state']);
        self::assertSame('OK', $iterationLogs[2]['context']['state']);
    }

    // -----------------------------------------------------------------
    // pollReport: state=ERROR на первой итерации → markFinalized('ERROR', message)
    // и UUID УЖЕ был сохранён create()'ом
    // -----------------------------------------------------------------
    public function testPollReportFinalizesRecordOnFirstIterationError(): void
    {
        $http = $this->buildHttpClientForError(
            tokenBody: $this->tokenBody('TKN-E1'),
            campaignListBody: $this->campaignListBody(1),
            statisticsBody: '{"UUID":"uuid-first-err"}',
            stateBody: json_encode(['state' => 'ERROR', 'error' => 'квота'], JSON_THROW_ON_ERROR),
        );

        $repo = $this->createMock(OzonAdPendingReportRepository::class);
        $repo->expects(self::once())
            ->method('create')
            ->with(self::COMPANY_ID, 'uuid-first-err', self::anything(), self::anything(), self::anything(), self::anything())
            ->willReturnCallback(
                static fn (string $companyId, string $ozonUuid, \DateTimeImmutable $from, \DateTimeImmutable $to, array $campaignIds, ?string $jobId): OzonAdPendingReport
                    => new OzonAdPendingReport($companyId, $ozonUuid, $from, $to, $campaignIds, $jobId),
            );
        $repo->expects(self::once())
            ->method('updateState')
            ->with(self::COMPANY_ID, 'uuid-first-err', 'ERROR', self::anything(), 1, self::anything())
            ->willReturn(1);
        $repo->expects(self::once())
            ->method('markFinalized')
            ->with(
                self::COMPANY_ID,
                'uuid-first-err',
                'ERROR',
                self::callback(static fn (?string $msg): bool => null !== $msg && str_contains($msg, 'ERROR') && str_contains($msg, 'квота')),
            )
            ->willReturn(1);

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger, $repo);

        $this->expectException(\RuntimeException::class);

        $client->fetchAdStatisticsRange(
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-03-01'),
            new \DateTimeImmutable('2026-03-01'),
            'aaaaaaaa-aaaa-aaaa-aaaa-000000000003',
        );
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Собирает валидный ZIP-архив в памяти, записывая через ZipArchive во
     * временный файл и считывая bytes. Даёт гарантированно валидную
     * последовательность заголовков и central directory (file_put_contents
     * + ручное формирование ZIP-заголовков хрупко и часто проваливает
     * ZipArchive::open() по тонкостям CRC/сжатия).
     *
     * @param array<string, string> $files name => content
     */
    private function buildZipBytes(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ozon-test-zip-');
        self::assertNotFalse($tmp, 'Failed to create temp file');

        try {
            $zip = new \ZipArchive();
            $opened = $zip->open($tmp, \ZipArchive::OVERWRITE);
            self::assertTrue(true === $opened, 'ZipArchive::open() must succeed');

            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();

            $bytes = file_get_contents($tmp);
            self::assertNotFalse($bytes, 'Failed to read back zip');

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    // -----------------------------------------------------------------
    // legacy helpers (preserved)
    // -----------------------------------------------------------------

    /**
     * @param list<array{campaign_id: string, campaign_name: string, rows: list<array<string, mixed>>}> $campaigns
     *
     * @return array{campaign_id: string, campaign_name: string, rows: list<array<string, mixed>>}
     */
    private function findCampaign(array $campaigns, string $id): array
    {
        foreach ($campaigns as $c) {
            if ($c['campaign_id'] === $id) {
                return $c;
            }
        }
        self::fail("Campaign $id not found");
    }

    private function loadFixture(string $name): string
    {
        $path = __DIR__.'/fixtures/ozon/'.$name;
        $csv = file_get_contents($path);
        self::assertNotFalse($csv, "Fixture $name must exist");

        return $csv;
    }

    private function tokenBody(string $token): string
    {
        return json_encode([
            'access_token' => $token,
            'expires_in' => 1800,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Generates a valid /campaign list JSON with $n SKU campaigns (ids 111, 222, 333…).
     */
    private function campaignListBody(int $n): string
    {
        $list = [];
        for ($i = 1; $i <= $n; ++$i) {
            $id = (string) ($i * 111);
            $list[] = [
                'id' => $id,
                'title' => 'Campaign '.chr(64 + $i),
                'advObjectType' => 'SKU',
            ];
        }

        return json_encode(['list' => $list], JSON_THROW_ON_ERROR);
    }

    /**
     * /campaign list with explicit state per campaign (covers the client-side
     * filter by state).
     *
     * @param list<array{id: string, title: string, state: string}> $campaigns
     */
    private function campaignListBodyWithStates(array $campaigns): string
    {
        $list = [];
        foreach ($campaigns as $c) {
            $list[] = [
                'id' => $c['id'],
                'title' => $c['title'],
                'advObjectType' => 'SKU',
                'state' => $c['state'],
            ];
        }

        return json_encode(['list' => $list], JSON_THROW_ON_ERROR);
    }

    private function stateReadyBody(string $link): string
    {
        return json_encode(['state' => 'OK', 'link' => $link], JSON_THROW_ON_ERROR);
    }

    /**
     * Happy-path HTTP sequence: token → /campaign → /statistics → /state → download.
     */
    private function buildHttpClientForRange(
        string $tokenBody,
        string $campaignListBody,
        string $statisticsBody,
        string $stateBody,
        string $downloadCsv,
    ): MockHttpClient {
        return new MockHttpClient([
            new MockResponse($tokenBody),
            new MockResponse($campaignListBody),
            new MockResponse($statisticsBody),
            new MockResponse($stateBody),
            new MockResponse($downloadCsv),
        ]);
    }

    /**
     * Error HTTP sequence: token → /campaign → /statistics → /state (state=ERROR).
     * Нет download-шага — pollReport() бросает исключение до него.
     */
    private function buildHttpClientForError(
        string $tokenBody,
        string $campaignListBody,
        string $statisticsBody,
        string $stateBody,
    ): MockHttpClient {
        return new MockHttpClient([
            new MockResponse($tokenBody),
            new MockResponse($campaignListBody),
            new MockResponse($statisticsBody),
            new MockResponse($stateBody),
        ]);
    }

    /**
     * Wraps a list of prepared MockResponses so that $log captures (method, url, Authorization)
     * for every request in order. Returns a callable compatible with MockHttpClient.
     *
     * @param list<array{method: string, url: string, auth: ?string}> $log
     * @param list<MockResponse>                                      $responses
     */
    private function scriptedResponsesWithRecording(array &$log, array $responses): callable
    {
        $i = 0;

        return function (string $method, string $url, array $options) use (&$log, $responses, &$i): ResponseInterface {
            $auth = null;
            foreach ($options['headers'] ?? [] as $h) {
                if (is_string($h) && str_starts_with($h, 'Authorization: ')) {
                    $auth = substr($h, strlen('Authorization: '));
                    break;
                }
            }
            $log[] = ['method' => $method, 'url' => $url, 'auth' => $auth];

            if (!isset($responses[$i])) {
                throw new \LogicException(sprintf('MockHttpClient: no scripted response for request #%d (%s %s)', $i + 1, $method, $url));
            }

            return $responses[$i++];
        };
    }
}
