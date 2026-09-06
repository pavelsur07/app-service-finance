<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Query;

use App\Inventory\Domain\StockSnapshotFreshnessPolicy;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Query\StockQtyByListingOnDateQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class StockQtyByListingOnDateQueryTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testUsesExactDateWhenSnapshotExists(): void
    {
        $reportDate = new \DateTimeImmutable('2026-04-30');
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'DISTINCT ON (candidate.source)')
                    && str_contains($sql, 's.snapshot_session_id = latest.snapshot_session_id')
                    && str_contains($sql, 's.status = :status')
                    && !str_contains($sql, 'candidate.status')
                    && str_contains($sql, 'candidate.listing_id IS NOT NULL')
                    && str_contains($sql, 'latest.snapshot_date >= :earliestAcceptableDate')
                ),
                self::callback(static fn (array $params): bool => self::COMPANY_ID === $params['companyId']
                    && '2026-04-30' === $params['reportDate']
                    && $params['status'] === StockStatus::Available->value
                    && '2026-04-28' === $params['earliestAcceptableDate']
                ),
            )
            ->willReturn([
                ['source' => 'ozon', 'snapshot_date' => '2026-04-30', 'listing_id' => 'l-1', 'stock_qty' => '10.5555'],
            ]);

        $result = $this->query($connection)->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['l-1' => 10.556], $result->qtyByListingId);
        self::assertSame(['ozon' => '2026-04-30'], $result->snapshotDateBySource);
        self::assertSame([], $result->staleSources);
    }

    public function testFallsBackToLatestSnapshotDateOnOrBeforeReportDate(): void
    {
        $reportDate = new \DateTimeImmutable('2026-05-02');
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::anything(),
                self::callback(static fn (array $params): bool => '2026-05-02' === $params['reportDate']),
            )
            ->willReturn([
                ['source' => 'ozon', 'snapshot_date' => '2026-05-01', 'listing_id' => 'l-2', 'stock_qty' => '7'],
            ]);

        $result = $this->query($connection)->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['l-2' => 7.0], $result->qtyByListingId);
        self::assertSame(['ozon' => '2026-05-01'], $result->snapshotDateBySource);
    }

    public function testAggregatesRowsReturnedForLatestSessionPerSource(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['source' => 'ozon', 'snapshot_date' => '2026-05-01', 'listing_id' => 'ozon-listing', 'stock_qty' => '3.25'],
                ['source' => 'wildberries', 'snapshot_date' => '2026-05-01', 'listing_id' => 'wb-listing', 'stock_qty' => '7'],
            ]);

        $result = $this->query($connection)->execute(self::COMPANY_ID, new \DateTimeImmutable('2026-05-01'));

        self::assertSame(['ozon-listing' => 3.25, 'wb-listing' => 7.0], $result->qtyByListingId);
        self::assertSame(['ozon' => '2026-05-01', 'wildberries' => '2026-05-01'], $result->snapshotDateBySource);
    }

    public function testReturnsEmptyWhenNoSnapshotDate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $result = $this->query($connection)->execute(self::COMPANY_ID, new \DateTimeImmutable('2026-05-10'));

        self::assertSame([], $result->qtyByListingId);
        self::assertSame([], $result->snapshotDateBySource);
        self::assertSame([], $result->staleSources);
    }

    public function testStaleSourceIsReportedAndItsQuantitiesAreNotUsed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                // Протухший источник приходит строкой с датой и listing_id = null (LEFT JOIN).
                ['source' => 'ozon', 'snapshot_date' => '2026-05-23', 'listing_id' => null, 'stock_qty' => null],
                ['source' => 'wildberries', 'snapshot_date' => '2026-09-06', 'listing_id' => 'wb-listing', 'stock_qty' => '4'],
            ]);

        $result = $this->query($connection)->execute(self::COMPANY_ID, new \DateTimeImmutable('2026-09-06'));

        self::assertSame(['wb-listing' => 4.0], $result->qtyByListingId);
        self::assertSame(['wildberries' => '2026-09-06'], $result->snapshotDateBySource);
        self::assertSame(['ozon' => '2026-05-23'], $result->staleSources);
    }

    public function testFreshSourceWithoutListingsIsNotReportedAsStale(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['source' => 'ozon', 'snapshot_date' => '2026-09-06', 'listing_id' => null, 'stock_qty' => null],
            ]);

        $result = $this->query($connection)->execute(self::COMPANY_ID, new \DateTimeImmutable('2026-09-06'));

        self::assertSame([], $result->qtyByListingId);
        self::assertSame(['ozon' => '2026-09-06'], $result->snapshotDateBySource);
        self::assertSame([], $result->staleSources);
    }

    private function query(Connection $connection): StockQtyByListingOnDateQuery
    {
        return new StockQtyByListingOnDateQuery($connection, new StockSnapshotFreshnessPolicy());
    }
}
