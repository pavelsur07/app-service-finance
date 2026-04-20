<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
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

    /** @var AbstractLogger&object{records: array<int, array{level: string, message: string, context: array<string, mixed>}>} */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->facade = $this->createMock(MarketplaceFacade::class);
        $this->facade->method('getConnectionCredentials')
            ->with(self::COMPANY_ID, MarketplaceType::OZON, MarketplaceConnectionType::PERFORMANCE)
            ->willReturn(['api_key' => self::CLIENT_SECRET, 'client_id' => self::CLIENT_ID]);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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
        $clientIso = new OzonAdClient($httpIso, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);
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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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
        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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
        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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

        $client = new OzonAdClient($http, $this->facade, new ArrayAdapter(), $this->logger, $this->logger);

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
    // helpers
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
