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
