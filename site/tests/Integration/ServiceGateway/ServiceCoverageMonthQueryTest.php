<?php

declare(strict_types=1);

namespace App\Tests\Integration\ServiceGateway;

use App\ServiceGateway\Infrastructure\Query\ServiceCoverageMonthQuery;
use App\Tests\Builders\ServiceGateway\ServiceCoverageRowBuilder;
use App\Tests\Builders\ServiceGateway\ServiceCoverageSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ServiceCoverageMonthQueryTest extends IntegrationTestCase
{
    private function query(): ServiceCoverageMonthQuery
    {
        // Сервис пока без потребителя в контейнере (страница — этап 3), поэтому вручную.
        return new ServiceCoverageMonthQuery($this->connection);
    }

    public function testReturnsDaysMonthKeyAndReferenceOfMonth(): void
    {
        $this->em->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->build());
        foreach (['2026-09-30', '2026-10-01', '2026-10-17', '2026-11-01'] as $day) {
            $this->em->persist(ServiceCoverageRowBuilder::aRow()->withPeriodKey($day)->build());
        }
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withResourceType('ozon.finance.realization')->withPeriodKey('2026-10')->withCounts(4, 3, pending: 1)->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withResourceType('ozon.finance.realization')->withPeriodKey('2026-09')->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withResourceType('ozon.finance.accrual-types')->withPeriodKey('ref')
            ->withLastSuccessAt(new \DateTimeImmutable('2026-10-18 00:05:00'))->build());
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withService('wb-fetcher')->withPeriodKey('2026-10-02')->build());
        $this->em->flush();
        $this->em->clear();

        $month = $this->query()->fetch('ozon-fetcher', '2026-10');

        self::assertSame('2026-10', $month->month);
        self::assertNotNull($month->snapshot);
        self::assertSame(4, $month->snapshot->cabinetsTotal);
        self::assertSame('2026-10-18 09:00:05', $month->snapshot->refreshedAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01', $month->snapshot->periodFrom->format('Y-m-d'));

        $keys = array_map(static fn ($c): string => $c->resourceType.' '.$c->periodKey, $month->cells);
        self::assertSame([
            'ozon.finance.accrual-by-day 2026-10-01',
            'ozon.finance.accrual-by-day 2026-10-17',
            'ozon.finance.accrual-types ref',
            'ozon.finance.realization 2026-10',
        ], $keys);
        self::assertSame(1, $month->cells[3]->byStatus->pending);
        self::assertSame(3, $month->cells[3]->byStatus->done);
        self::assertSame('2026-10-18 00:05:00', $month->cells[2]->lastSuccessAt?->format('Y-m-d H:i:s'));
        self::assertNull($month->cells[0]->lastSuccessAt);
    }

    public function testReturnsIsoWeeksOverlappingMonth(): void
    {
        $this->em->persist(ServiceCoverageSnapshotBuilder::aSnapshot()->withService('wb-fetcher')->build());
        foreach (['2026-W43', '2026-W44', '2026-W46', '2026-W49', '2026-W50'] as $week) {
            $this->em->persist(ServiceCoverageRowBuilder::aRow()->withService('wb-fetcher')->withResourceType('wb.finance.sales-report.weekly')->withPeriodKey($week)->build());
        }
        $this->em->persist(ServiceCoverageRowBuilder::aRow()->withService('wb-fetcher')->withResourceType('wb.finance.sales-report.daily')->withPeriodKey('2026-11-01')->build());
        $this->em->flush();
        $this->em->clear();

        $month = $this->query()->fetch('wb-fetcher', '2026-11');

        self::assertSame([
            'wb.finance.sales-report.daily 2026-11-01',
            'wb.finance.sales-report.weekly 2026-W44', // 26.10–01.11 — захватывает 1 ноября
            'wb.finance.sales-report.weekly 2026-W46',
            'wb.finance.sales-report.weekly 2026-W49', // 30.11–06.12
        ], array_map(static fn ($c): string => $c->resourceType.' '.$c->periodKey, $month->cells));
    }

    public function testEmptyReadModel(): void
    {
        $month = $this->query()->fetch('ozon-fetcher', '2026-10');

        self::assertNull($month->snapshot);
        self::assertSame([], $month->cells);
    }

    public function testRejectsMalformedMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->fetch('ozon-fetcher', '2026-10-01');
    }
}
