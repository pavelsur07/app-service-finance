<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Query;

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
            ->method('fetchOne')
            ->willReturn('2026-04-30');

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['snapshot_at' => '2026-04-30 15:00:00']);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('s.snapshot_at = :snapshotAt'),
                self::callback(static fn (array $params): bool =>
                    $params['companyId'] === self::COMPANY_ID
                    && $params['snapshotDate'] === '2026-04-30'
                    && $params['snapshotAt'] instanceof \DateTimeImmutable
                    && $params['snapshotAt']->format('Y-m-d H:i:s') === '2026-04-30 15:00:00'
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
            ->method('fetchOne')
            ->willReturn('2026-05-01');

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('MAX(s.snapshot_at)'),
                self::callback(static fn (array $params): bool =>
                    $params['companyId'] === self::COMPANY_ID
                    && $params['snapshotDate'] === '2026-05-01'
                ),
            )
            ->willReturn(['snapshot_at' => '2026-05-01 23:59:59']);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::anything(),
                self::callback(static fn (array $params): bool =>
                    $params['snapshotDate'] === '2026-05-01'
                    && $params['snapshotAt'] instanceof \DateTimeImmutable
                    && $params['snapshotAt']->format('Y-m-d H:i:s') === '2026-05-01 23:59:59'
                ),
            )
            ->willReturn([
                ['listing_id' => 'l-2', 'stock_qty' => '7'],
            ]);

        $query = new StockQtyByListingOnDateQuery($connection);
        $result = $query->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['l-2' => 7.0], $result);
    }



    public function testUsesLatestSnapshotAtInsideSelectedDay(): void
    {
        $reportDate = new \DateTimeImmutable('2026-05-01');
        $connection = $this->createMock(Connection::class);

        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn('2026-05-01');

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['snapshot_at' => '2026-05-01 20:15:00']);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('s.snapshot_at = :snapshotAt'),
                self::callback(static fn (array $params): bool =>
                    $params['snapshotDate'] === '2026-05-01'
                    && $params['snapshotAt'] instanceof \DateTimeImmutable
                    && $params['snapshotAt']->format('Y-m-d H:i:s') === '2026-05-01 20:15:00'
                ),
            )
            ->willReturn([
                ['listing_id' => 'l-3', 'stock_qty' => '3.25'],
            ]);

        $query = new StockQtyByListingOnDateQuery($connection);
        $result = $query->execute(self::COMPANY_ID, $reportDate);

        self::assertSame(['l-3' => 3.25], $result);
    }

    public function testReturnsEmptyWhenNoSnapshotDate(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('fetchAllAssociative');

        $query = new StockQtyByListingOnDateQuery($connection);

        self::assertSame([], $query->execute(self::COMPANY_ID, new \DateTimeImmutable('2026-05-10')));
    }
}
