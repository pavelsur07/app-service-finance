<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Application\Action\RefreshServiceCoverageAction;
use App\ServiceGateway\Application\DTO\ServiceCoverageReport;
use App\ServiceGateway\Application\DTO\ServiceCoverageReportRow;
use App\ServiceGateway\Application\DTO\ServiceCoverageStatusCounts;
use App\ServiceGateway\Exception\ServiceUnavailableException;
use App\Tests\Builders\ServiceGateway\ServiceCoverageRowBuilder;
use App\Tests\Builders\ServiceGateway\ServiceCoverageSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Unit\ServiceGateway\Infrastructure\Client\ServiceCoverageV1ParserTest;

final class RefreshServiceCoverageActionTest extends IntegrationTestCase
{
    private function action(): RefreshServiceCoverageAction
    {
        /** @var RefreshServiceCoverageAction $action */
        $action = self::getContainer()->get(RefreshServiceCoverageAction::class);

        return $action;
    }

    public function testReplacesSnapshotAndRowsOfService(): void
    {
        $this->em->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->withCabinetsTotal(2)->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withPeriodKey('2026-08-01')->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withService('wb-fetcher')->build());
        $this->em->flush();
        $this->em->clear();

        $now = new \DateTimeImmutable('2026-10-18 09:10:00');
        $result = ($this->action())(new FakeServiceCoverageClient(), $now);

        self::assertSame(3, $result->rows);
        self::assertSame(4, $result->cabinetsTotal);

        $snapshot = $this->connection->fetchAllAssociative('SELECT service, cabinets_total, refreshed_at, period_from, period_to FROM service_coverage_snapshot');
        self::assertSame([['service' => 'ozon-fetcher', 'cabinets_total' => 4, 'refreshed_at' => '2026-10-18 09:10:00', 'period_from' => '2026-08-01', 'period_to' => '2026-10-31']], $snapshot);

        $rows = $this->connection->fetchAllAssociative('SELECT service, resource_type, period_key, done, running, failed_permanent FROM service_coverage_row ORDER BY service, resource_type, period_key');
        self::assertSame([
            ['service' => 'ozon-fetcher', 'resource_type' => 'ozon.finance.accrual-by-day', 'period_key' => '2026-10-17', 'done' => 3, 'running' => 1, 'failed_permanent' => 0],
            ['service' => 'ozon-fetcher', 'resource_type' => 'ozon.finance.accrual-types', 'period_key' => 'ref', 'done' => 3, 'running' => 0, 'failed_permanent' => 1],
            ['service' => 'ozon-fetcher', 'resource_type' => 'ozon.finance.realization', 'period_key' => '2026-09', 'done' => 4, 'running' => 0, 'failed_permanent' => 0],
            ['service' => 'wb-fetcher', 'resource_type' => ServiceCoverageRowBuilder::DEFAULT_RESOURCE, 'period_key' => '2026-10-15', 'done' => 4, 'running' => 0, 'failed_permanent' => 0],
        ], $rows, 'старая строка сервиса удалена, чужой сервис не тронут');
    }

    public function testEmptyReportKeepsSnapshotTime(): void
    {
        $data = ServiceCoverageV1ParserTest::fixture();
        $data['rows'] = [];
        $data['cabinets_total'] = 0;

        $result = ($this->action())(new FakeServiceCoverageClient($data), new \DateTimeImmutable('2026-10-18 09:20:00'));
        $this->em->clear();

        self::assertSame(0, $result->rows);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
        self::assertSame('2026-10-18 09:20:00', $this->connection->fetchOne('SELECT refreshed_at FROM service_coverage_snapshot'));
    }

    public function testRepeatedRefreshIsIdempotent(): void
    {
        ($this->action())(new FakeServiceCoverageClient(), new \DateTimeImmutable('2026-10-18 09:10:00'));
        $this->em->clear();
        ($this->action())(new FakeServiceCoverageClient(), new \DateTimeImmutable('2026-10-18 09:20:00'));

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_snapshot'));
    }

    public function testFailedFetchKeepsReadModel(): void
    {
        $this->em->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->build());
        $this->em->flush();
        $this->em->clear();

        try {
            ($this->action())(new FakeServiceCoverageClient(ServiceUnavailableException::status('ozon-fetcher', 503)), new \DateTimeImmutable());
            self::fail('expected ServiceUnavailableException');
        } catch (ServiceUnavailableException) {
        }

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM service_coverage_row'));
        self::assertSame('2026-10-18 09:00:05', $this->connection->fetchOne('SELECT refreshed_at FROM service_coverage_snapshot'));
    }

    public function testFailureInsideTransactionRollsBack(): void
    {
        $this->em->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->build());
        $this->em->flush();
        $this->em->clear();

        $counts = new ServiceCoverageStatusCounts(1, 0, 0, 0, 0, 0);
        $report = new ServiceCoverageReport('ozon-fetcher', new \DateTimeImmutable(), new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-10-31'), 4, [
            new ServiceCoverageReportRow('ozon.finance.accrual-by-day', '2026-10-16', 4, $counts, null),
            new ServiceCoverageReportRow('ozon.finance.accrual-by-day', 'broken', 4, $counts, null),
        ]);

        try {
            ($this->action())(new FakeServiceCoverageClient($report), new \DateTimeImmutable());
            self::fail('expected invariant violation');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(['2026-10-15'], $this->connection->fetchFirstColumn('SELECT period_key FROM service_coverage_row'), 'удаление откатилось');
        self::assertSame('2026-10-18 09:00:05', $this->connection->fetchOne('SELECT refreshed_at FROM service_coverage_snapshot'));
    }
}
