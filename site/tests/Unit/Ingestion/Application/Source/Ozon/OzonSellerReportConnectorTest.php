<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\PullRequest;
use App\Ingestion\Application\DTO\PushRequest;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector;
use App\Ingestion\Enum\Capability;
use App\Ingestion\Exception\UnsupportedCapabilityException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonAccrualClientInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonRawPage;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OzonSellerReportConnectorTest extends TestCase
{
    public function testCapabilitiesDiscoverAndUnsupportedPush(): void
    {
        $connector = new OzonSellerReportConnector($this->unusedAccrualClient());
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

    public function testLegacyOzonResourcesAreUnsupported(): void
    {
        $connector = new OzonSellerReportConnector($this->unusedAccrualClient());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported Ozon resource type');

        $connector->pull(new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: 'ozon_seller_daily_report',
            cursorValue: null,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            syncJobId: Uuid::uuid7()->toString(),
        ));
    }

    public function testPullAccrualPostingsRejectsDateBackfill(): void
    {
        $accrualClient = new class implements OzonAccrualClientInterface {
            public bool $called = false;

            public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
            {
                $this->called = true;

                throw new \LogicException('Not used.');
            }

            public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }
        };

        $connector = new OzonSellerReportConnector($accrualClient);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('posting_numbers');

        try {
            $connector->pull(new PullRequest(
                companyId: Uuid::uuid7()->toString(),
                connectionRef: 'connection-1',
                shopRef: 'shop-1',
                resourceType: OzonResourceType::ACCRUAL_POSTINGS,
                cursorValue: null,
                windowFrom: new \DateTimeImmutable('2026-06-01'),
                windowTo: new \DateTimeImmutable('2026-06-18'),
                syncJobId: Uuid::uuid7()->toString(),
            ));
        } finally {
            self::assertFalse($accrualClient->called);
        }
    }

    public function testPullAccrualByDayStoresEmptyMarkerForEmptyResponse(): void
    {
        $accrualClient = new class implements OzonAccrualClientInterface {
            /**
             * @var list<string>
             */
            public array $dates = [];

            public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
            {
                $this->dates[] = $date->format('Y-m-d');

                return new OzonRawPage(rows: [], hasMore: false, metadata: ['endpoint' => '/v1/finance/accrual/by-day']);
            }

            public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }
        };

        $connector = new OzonSellerReportConnector($accrualClient);
        $result = $connector->pull(new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            cursorValue: null,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-02'),
            syncJobId: Uuid::uuid7()->toString(),
        ));

        self::assertSame(OzonResourceType::ACCRUAL_BY_DAY, $result->rawBatch->resourceType);
        self::assertSame('accrual-by-day:2026-06-01:2026-06-02', $result->rawBatch->externalId);
        self::assertSame(['2026-06-01', '2026-06-02'], $accrualClient->dates);
        self::assertSame([
            [
                '_ingestion_empty' => true,
                '_ingestion_resource' => OzonResourceType::ACCRUAL_BY_DAY,
                '_ingestion_metadata' => [
                    'windowFrom' => '2026-06-01',
                    'windowTo' => '2026-06-02',
                    'apiMetadata' => [
                        [
                            'date' => '2026-06-01',
                            'metadata' => ['endpoint' => '/v1/finance/accrual/by-day'],
                        ],
                        [
                            'date' => '2026-06-02',
                            'metadata' => ['endpoint' => '/v1/finance/accrual/by-day'],
                        ],
                    ],
                ],
            ],
        ], $result->rawBatch->rows);
        self::assertNull($result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    public function testPullAccrualByDayFetchesEachDateInWindow(): void
    {
        $accrualClient = new class implements OzonAccrualClientInterface {
            /**
             * @var list<string>
             */
            public array $dates = [];

            public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
            {
                $dateString = $date->format('Y-m-d');
                $this->dates[] = $dateString;

                return new OzonRawPage(rows: [['date' => $dateString, 'accrual_id' => $dateString]], hasMore: false);
            }

            public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }
        };

        $connector = new OzonSellerReportConnector($accrualClient);
        $result = $connector->pull(new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            cursorValue: null,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-02'),
            syncJobId: Uuid::uuid7()->toString(),
        ));

        self::assertSame(['2026-06-01', '2026-06-02'], $accrualClient->dates);
        self::assertSame([
            ['date' => '2026-06-01', 'accrual_id' => '2026-06-01'],
            ['date' => '2026-06-02', 'accrual_id' => '2026-06-02'],
        ], $result->rawBatch->rows);
        self::assertSame('accrual-by-day:2026-06-01:2026-06-02', $result->rawBatch->externalId);
    }

    public function testPullAccrualTypesFetchesStaticDictionaryOnce(): void
    {
        $accrualClient = new class implements OzonAccrualClientInterface {
            public int $calls = 0;

            public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
            {
                ++$this->calls;

                return new OzonRawPage(rows: [['type_id' => 29, 'name' => 'Delivery']], hasMore: false, metadata: ['endpoint' => '/v1/finance/accrual/types']);
            }
        };

        $connector = new OzonSellerReportConnector($accrualClient);
        $result = $connector->pull(new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_TYPES,
            cursorValue: null,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-30'),
            syncJobId: Uuid::uuid7()->toString(),
        ));

        self::assertSame(1, $accrualClient->calls);
        self::assertSame('accrual-types', $result->rawBatch->externalId);
        self::assertSame([['type_id' => 29, 'name' => 'Delivery']], $result->rawBatch->rows);
        self::assertNull($result->nextCursorValue);
        self::assertFalse($result->hasMore);
    }

    public function testPullAccrualByDayCanonicalisesRowOrderForStableHash(): void
    {
        $rows = [
            ['date' => '2026-06-01', 'accrual_id' => '300', 'amount' => '3'],
            ['date' => '2026-06-01', 'accrual_id' => '100', 'amount' => '1'],
            ['date' => '2026-06-01', 'accrual_id' => '200', 'amount' => '2'],
        ];

        $forward = $this->pullSingleDay($rows);
        $reversed = $this->pullSingleDay(array_reverse($rows));

        $codec = new RawNdjsonCodec();

        // Order of the source response must not change the persisted payload.
        self::assertSame($forward, $reversed);
        self::assertSame($codec->encodeRows($forward), $codec->encodeRows($reversed));

        // Sanity: rows are ordered by the canonical key (accrual_id ascending here).
        self::assertSame(['100', '200', '300'], array_map(static fn (array $row): string => $row['accrual_id'], $forward));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function pullSingleDay(array $rows): array
    {
        $accrualClient = new class($rows) implements OzonAccrualClientInterface {
            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(private array $rows)
            {
            }

            public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
            {
                return new OzonRawPage(rows: $this->rows, hasMore: false);
            }

            public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }
        };

        $connector = new OzonSellerReportConnector($accrualClient);
        $result = $connector->pull(new PullRequest(
            companyId: Uuid::uuid7()->toString(),
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            cursorValue: null,
            windowFrom: new \DateTimeImmutable('2026-06-01'),
            windowTo: new \DateTimeImmutable('2026-06-01'),
            syncJobId: Uuid::uuid7()->toString(),
        ));

        return $result->rawBatch->rows;
    }

    private function unusedAccrualClient(): OzonAccrualClientInterface
    {
        return new class implements OzonAccrualClientInterface {
            public function fetchPostings(string $companyId, string $connectionRef, array $postingNumbers): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchByDay(string $companyId, string $connectionRef, \DateTimeImmutable $date): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }

            public function fetchTypes(string $companyId, string $connectionRef): OzonRawPage
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
