<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use PHPUnit\Framework\TestCase;

final class MarketplaceWeekPartitionServiceTest extends TestCase
{
    private MarketplaceWeekPartitionService $service;

    protected function setUp(): void
    {
        $this->service = new MarketplaceWeekPartitionService();
    }

    /**
     * from=2026-01-01 (чт) → первая партия до ближайшего Вс (04.01), без отката до Пн.
     */
    public function testFirstPartitionStartsOnJanFirst(): void
    {
        $partitions = $this->service->buildPartitions(
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-11'),
        );

        self::assertSame('2026-01-01 00:00:00', $partitions[0]['from']);
        self::assertSame('2026-01-04 23:59:59', $partitions[0]['to']);

        self::assertSame('2026-01-05 00:00:00', $partitions[1]['from']);
        self::assertSame('2026-01-11 23:59:59', $partitions[1]['to']);
    }

    /**
     * Полная неделя Пн–Вс без пересечения границы месяца → одна партия.
     */
    public function testFullWeekNoMonthBoundary(): void
    {
        $partitions = $this->service->buildPartitions(
            new \DateTimeImmutable('2026-01-05'),
            new \DateTimeImmutable('2026-01-11'),
        );

        self::assertCount(1, $partitions);
        self::assertSame('2026-01-05 00:00:00', $partitions[0]['from']);
        self::assertSame('2026-01-11 23:59:59', $partitions[0]['to']);
    }

    /**
     * Неделя 30.03–05.04 пересекает границу месяца → разбивается на две партии.
     */
    public function testMonthBoundarySplitsWeek(): void
    {
        $partitions = $this->service->buildPartitions(
            new \DateTimeImmutable('2026-03-30'),
            new \DateTimeImmutable('2026-04-05'),
        );

        self::assertCount(2, $partitions);

        self::assertSame('2026-03-30 00:00:00', $partitions[0]['from']);
        self::assertSame('2026-03-31 23:59:59', $partitions[0]['to']);

        self::assertSame('2026-04-01 00:00:00', $partitions[1]['from']);
        self::assertSame('2026-04-05 23:59:59', $partitions[1]['to']);
    }

    /**
     * Неполная неделя обрезается до $to, не выходит за его пределы.
     */
    public function testLastPartitionClampedToTo(): void
    {
        // from=2026-01-05 (Пн), to=2026-01-08 (Чт) — неполная неделя
        $partitions = $this->service->buildPartitions(
            new \DateTimeImmutable('2026-01-05'),
            new \DateTimeImmutable('2026-01-08'),
        );

        self::assertCount(1, $partitions);
        self::assertSame('2026-01-05 00:00:00', $partitions[0]['from']);
        self::assertSame('2026-01-08 23:59:59', $partitions[0]['to']);
    }

    /**
     * $from > $to → пустой массив.
     */
    public function testEmptyWhenFromAfterTo(): void
    {
        $partitions = $this->service->buildPartitions(
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-05'),
        );

        self::assertSame([], $partitions);
    }

    /**
     * $from == $to → одна партия из одного дня.
     */
    public function testSingleDayPartition(): void
    {
        $partitions = $this->service->buildPartitions(
            new \DateTimeImmutable('2026-01-07'),
            new \DateTimeImmutable('2026-01-07'),
        );

        self::assertCount(1, $partitions);
        self::assertSame('2026-01-07 00:00:00', $partitions[0]['from']);
        self::assertSame('2026-01-07 23:59:59', $partitions[0]['to']);
    }
}
