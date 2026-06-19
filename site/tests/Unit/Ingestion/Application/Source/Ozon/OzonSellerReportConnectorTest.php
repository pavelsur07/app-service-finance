<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonClientAdapterInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use App\Ingestion\Infrastructure\Api\Ozon\OzonShopDescriptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class OzonSellerReportConnectorTest extends TestCase
{
    public function testPullDailyReportUsesSevenDayWindowAndReturnsRawBatch(): void
    {
        $client = new class implements OzonClientAdapterInterface {
            public ?\DateTimeImmutable $from = null;
            public ?\DateTimeImmutable $to = null;

            public function fetchTransactionList(
                string $companyId,
                string $connectionRef,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                int $page,
                int $pageSize,
            ): OzonRawPage {
                $this->from = $from;
                $this->to = $to;

                return new OzonRawPage(rows: [['operation_id' => 'op-1']], hasMore: false);
            }

            public function fetchRealization(string $companyId, string $connectionRef, int $year, int $month): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function listClusters(string $companyId, string $connectionRef): array
            {
                return [new OzonShopDescriptor('shop-1', 'Shop 1')];
            }
        };

        $connector = new OzonSellerReportConnector($client, new NullLogger());
        $companyId = Uuid::uuid7()->toString();
        $syncJobId = Uuid::uuid7()->toString();

        $result = $connector->pull(new PullRequest(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::DAILY_REPORT,
            cursorValue: null,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            syncJobId: $syncJobId,
        ));

        self::assertEquals(new \DateTimeImmutable('2026-06-01 00:00:00'), $client->from);
        self::assertEquals(new \DateTimeImmutable('2026-06-07 23:59:59'), $client->to);
        self::assertSame('daily:2026-06-01:2026-06-07', $result->rawBatch->externalId);
        self::assertSame('2026-06-08', $result->nextCursorValue);
        self::assertTrue($result->hasMore);
        self::assertSame([['operation_id' => 'op-1']], $result->rawBatch->rows);
    }

    public function testPullDailyReportAdvancesCursorForNonWindowedIncremental(): void
    {
        $client = new class implements OzonClientAdapterInterface {
            public ?\DateTimeImmutable $from = null;
            public ?\DateTimeImmutable $to = null;

            public function fetchTransactionList(
                string $companyId,
                string $connectionRef,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                int $page,
                int $pageSize,
            ): OzonRawPage {
                $this->from = $from;
                $this->to = $to;

                return new OzonRawPage(rows: [['operation_id' => 'op-1']], hasMore: false);
            }

            public function fetchRealization(string $companyId, string $connectionRef, int $year, int $month): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function listClusters(string $companyId, string $connectionRef): array
            {
                return [new OzonShopDescriptor('shop-1', 'Shop 1')];
            }
        };

        $connector = new OzonSellerReportConnector($client, new NullLogger());
        $companyId = Uuid::uuid7()->toString();

        $result = $connector->pull(new PullRequest(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::DAILY_REPORT,
            cursorValue: '2026-06-12',
            windowFrom: null,
            windowTo: null,
            syncJobId: Uuid::uuid7()->toString(),
        ));

        self::assertEquals(new \DateTimeImmutable('2026-06-12 00:00:00'), $client->from);
        self::assertEquals(new \DateTimeImmutable('2026-06-18 23:59:59'), $client->to);
        self::assertSame('2026-06-19', $result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    public function testPullRealizationAdvancesCursorForNonWindowedIncremental(): void
    {
        $client = new class implements OzonClientAdapterInterface {
            public ?int $year = null;
            public ?int $month = null;

            public function fetchTransactionList(
                string $companyId,
                string $connectionRef,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                int $page,
                int $pageSize,
            ): OzonRawPage {
                throw new \LogicException('Not used.');
            }

            public function fetchRealization(string $companyId, string $connectionRef, int $year, int $month): OzonRawPage
            {
                $this->year = $year;
                $this->month = $month;

                return new OzonRawPage(
                    rows: [['operation_id' => 'op-1']],
                    hasMore: false,
                    metadata: ['header' => ['doc_number' => 'doc-1']],
                );
            }

            public function listClusters(string $companyId, string $connectionRef): array
            {
                return [new OzonShopDescriptor('shop-1', 'Shop 1')];
            }
        };

        $connector = new OzonSellerReportConnector($client, new NullLogger());

        $result = $connector->pull(new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::REALIZATION,
            cursorValue: '2026-05-01',
            windowFrom: null,
            windowTo: null,
            syncJobId: Uuid::uuid7()->toString(),
        ));

        self::assertSame(2026, $client->year);
        self::assertSame(5, $client->month);
        self::assertSame('2026-06-01', $result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    public function testCapabilitiesDiscoverAndUnsupportedPush(): void
    {
        $client = new class implements OzonClientAdapterInterface {
            public function fetchTransactionList(
                string $companyId,
                string $connectionRef,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                int $page,
                int $pageSize,
            ): OzonRawPage {
                throw new \LogicException('Not used.');
            }

            public function fetchRealization(string $companyId, string $connectionRef, int $year, int $month): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function listClusters(string $companyId, string $connectionRef): array
            {
                return [new OzonShopDescriptor('shop-1', 'Shop 1')];
            }
        };

        $connector = new OzonSellerReportConnector($client, new NullLogger());
        self::assertSame([Capability::CAN_DISCOVER_SHOPS, Capability::CAN_PULL], $connector->capabilities());
        self::assertSame('shop-1', $connector->discoverShops(Uuid::uuid7()->toString(), 'connection-1')[0]->externalId);

        $this->expectException(UnsupportedCapabilityException::class);
        $connector->push(new PushRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            documentType: 'doc',
            payload: [],
            idempotencyKey: 'key-1',
        ));
    }
}
