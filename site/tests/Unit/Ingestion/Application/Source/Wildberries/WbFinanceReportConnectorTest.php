<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\Source\Wildberries\WbFinanceReportConnector;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportClientInterface;
use App\Ingestion\Infrastructure\Api\Wildberries\WbFinanceReportPage;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;

final class WbFinanceReportConnectorTest extends TestCase
{
    public function testCapabilitiesDiscoverAndUnsupportedPush(): void
    {
        $connector = $this->connector($this->client(new WbFinanceReportPage([], null, false)));
        $connectionRef = Uuid::uuid7()->toString();

        self::assertSame([Capability::CAN_DISCOVER_SHOPS, Capability::CAN_PULL], $connector->capabilities());
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

    public function testPullStoresOnePageRawWithoutNormalization(): void
    {
        $client = $this->client(new WbFinanceReportPage(
            rows: [['rrdId' => 10, 'docTypeName' => 'Продажа']],
            nextRrdId: 10,
            hasMore: false,
            metadata: ['endpoint' => '/api/finance/v1/sales-reports/detailed'],
        ));
        $connector = $this->connector($client);

        $result = $connector->pull($this->request(
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-20'),
        ));

        self::assertSame(WbResourceType::FINANCE_SALES_REPORT_DETAILED, $result->rawBatch->resourceType);
        self::assertSame('wb-sales-report-detailed:2026-06-20:rrd-0', $result->rawBatch->externalId);
        self::assertFalse($result->normalizeRawRecords);
        self::assertFalse($result->hasMore);
        self::assertNull($result->nextCursorValue);
        self::assertSame([
            [
                'rrdId' => 10,
                'docTypeName' => 'Продажа',
                '_ingestion_resource' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            ],
        ], $result->rawBatch->rows);
        self::assertSame([
            ['2026-06-20', 0],
        ], $client->calls);
    }

    public function testPullFullPageReturnsDelayedContinuationCursor(): void
    {
        $client = $this->client(new WbFinanceReportPage(
            rows: [['rrdId' => 100]],
            nextRrdId: 100,
            hasMore: true,
            metadata: ['date' => '2026-06-20'],
        ));
        $connector = $this->connector($client, continuationDelaySeconds: 70);

        $result = $connector->pull($this->request(
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-20'),
        ));

        self::assertTrue($result->hasMore);
        self::assertSame(70, $result->continuationDelaySeconds);
        self::assertSame('{"date":"2026-06-20","rrdId":100}', $result->nextCursorValue);
    }

    public function testPullContinuationUsesEncodedCursor(): void
    {
        $client = $this->client(new WbFinanceReportPage([], null, false));
        $connector = $this->connector($client);

        $connector->pull($this->request(cursorValue: '{"date":"2026-06-20","rrdId":100}'));

        self::assertSame([
            ['2026-06-20', 100],
        ], $client->calls);
    }

    public function testPullEmptyPageStoresEmptyMarker(): void
    {
        $connector = $this->connector($this->client(new WbFinanceReportPage(
            rows: [],
            nextRrdId: null,
            hasMore: false,
            metadata: ['date' => '2026-06-20'],
        )));

        $result = $connector->pull($this->request(
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-20'),
        ));

        self::assertSame([[
            '_ingestion_empty' => true,
            '_ingestion_resource' => WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            '_ingestion_metadata' => ['date' => '2026-06-20'],
        ]], $result->rawBatch->rows);
    }

    public function testWindowMustBeOneDay(): void
    {
        $connector = $this->connector($this->client(new WbFinanceReportPage([], null, false)));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one-day chunks');

        $connector->pull($this->request(
            windowFrom: new \DateTimeImmutable('2026-06-20'),
            windowTo: new \DateTimeImmutable('2026-06-21'),
        ));
    }

    public function testIncrementalCursorAdvancesFromYesterdayToToday(): void
    {
        $connector = $this->connector(
            $this->client(new WbFinanceReportPage(rows: [], nextRrdId: null, hasMore: false)),
            now: '2026-06-22T00:00:00+00:00',
        );

        $result = $connector->pull($this->request(cursorValue: '2026-06-21'));

        self::assertSame('2026-06-22', $result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    public function testIncrementalCursorAdvancesUntilYesterday(): void
    {
        $connector = $this->connector(
            $this->client(new WbFinanceReportPage(rows: [], nextRrdId: null, hasMore: false)),
            now: '2026-06-22T00:00:00+00:00',
        );

        $result = $connector->pull($this->request(cursorValue: '2026-06-20'));

        self::assertSame('2026-06-21', $result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    public function testIncrementalCursorDoesNotAdvancePastToday(): void
    {
        $connector = $this->connector(
            $this->client(new WbFinanceReportPage(rows: [], nextRrdId: null, hasMore: false)),
            now: '2026-06-22T00:00:00+00:00',
        );

        $result = $connector->pull($this->request(cursorValue: '2026-06-22'));

        self::assertNull($result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    private function request(
        ?string $cursorValue = null,
        ?\DateTimeImmutable $windowFrom = null,
        ?\DateTimeImmutable $windowTo = null,
    ): PullRequest {
        return new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            cursorValue: $cursorValue,
            windowFrom: $windowFrom,
            windowTo: $windowTo,
            syncJobId: Uuid::uuid7()->toString(),
        );
    }

    private function client(WbFinanceReportPage $page): WbFinanceReportClientInterface
    {
        return new class($page) implements WbFinanceReportClientInterface {
            /**
             * @var list<array{0: string, 1: int}>
             */
            public array $calls = [];

            public function __construct(private readonly WbFinanceReportPage $page)
            {
            }

            public function fetchDetailedDayPage(
                string $companyId,
                string $connectionRef,
                \DateTimeImmutable $date,
                int $rrdId,
                int $limit = 100000,
            ): WbFinanceReportPage {
                $this->calls[] = [$date->format('Y-m-d'), $rrdId];

                return $this->page;
            }
        };
    }

    private function connector(
        WbFinanceReportClientInterface $client,
        int $continuationDelaySeconds = 70,
        string $now = '2026-06-22T00:00:00+00:00',
    ): WbFinanceReportConnector {
        return new WbFinanceReportConnector(
            $client,
            new MockClock($now),
            $continuationDelaySeconds,
        );
    }
}
