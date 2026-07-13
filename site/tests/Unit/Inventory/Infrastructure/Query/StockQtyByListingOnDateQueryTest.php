<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Query;

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
                    && str_contains($sql, 'latest.snapshot_session_id = s.snapshot_session_id')
                    && str_contains($sql, 's.status = :status')
                    && !str_contains($sql, 'candidate.status')
                    && str_contains($sql, 'candidate.listing_id IS NOT NULL')
                ),
                self::callback(static fn (array $params): bool => self::COMPANY_ID === $params['companyId']
                    && '2026-04-30' === $params['reportDate']
                    && $params['status'] === StockStatus::Available->value
                ),
            )
            ->willReturn([
                ['listing_id' => 'l-1', 'stock_qty' => '10.5555'],
            ]);

        $query = new StockQtyByListingOnDateQuery($connection);
        $result = $query->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['l-1' => 10.556], $result);
    }

    public function testFallsBackToLatestSnapshotDateOnOrBeforeReportDate(): void
    {
        $reportDate = new \DateTimeImmutable('2026-05-02');
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::anything(),
                self::callback(static fn (array $params): bool => '2026-05-02' === $params['reportDate']
                ),
            )
            ->willReturn([
                ['listing_id' => 'l-2', 'stock_qty' => '7'],
            ]);

        $query = new StockQtyByListingOnDateQuery($connection);
        $result = $query->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['l-2' => 7.0], $result);
    }

    public function testAggregatesRowsReturnedForLatestSessionPerSource(): void
    {
        $reportDate = new \DateTimeImmutable('2026-05-01');
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['listing_id' => 'ozon-listing', 'stock_qty' => '3.25'],
                ['listing_id' => 'wb-listing', 'stock_qty' => '7'],
            ]);

        $query = new StockQtyByListingOnDateQuery($connection);
        $result = $query->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['ozon-listing' => 3.25, 'wb-listing' => 7.0], $result);
    }

    public function testReturnsEmptyWhenNoSnapshotDate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn([]);

        $query = new StockQtyByListingOnDateQuery($connection);

        self::assertSame([], $query->execute(self::COMPANY_ID, new \DateTimeImmutable('2026-05-10')));
    }
}
