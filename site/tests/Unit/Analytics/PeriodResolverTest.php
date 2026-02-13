<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Api\Request\SnapshotQuery;
use App\Analytics\Application\PeriodResolver;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PeriodResolverTest extends TestCase
{
    private PeriodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PeriodResolver();
    }

    public function testResolvesDayPreset(): void
    {
        $period = $this->resolver->resolve(
            new SnapshotQuery('day', null, null),
            new DateTimeImmutable('2026-03-17 18:20:00', new DateTimeZone('UTC')),
        );

        self::assertSame('2026-03-17', $period->getFrom()->format('Y-m-d'));
        self::assertSame('2026-03-17', $period->getTo()->format('Y-m-d'));
    }

    public function testResolvesWeekPresetAsCurrentCalendarWeekMondayToSunday(): void
    {
        $period = $this->resolver->resolve(
            new SnapshotQuery('week', null, null),
            new DateTimeImmutable('2026-03-18', new DateTimeZone('UTC')),
        );

        self::assertSame('2026-03-16', $period->getFrom()->format('Y-m-d'));
        self::assertSame('2026-03-22', $period->getTo()->format('Y-m-d'));
    }

    public function testResolvesMonthPresetAsCurrentMonth(): void
    {
        $period = $this->resolver->resolve(
            new SnapshotQuery('month', null, null),
            new DateTimeImmutable('2026-02-12', new DateTimeZone('UTC')),
        );

        self::assertSame('2026-02-01', $period->getFrom()->format('Y-m-d'));
        self::assertSame('2026-02-28', $period->getTo()->format('Y-m-d'));
    }

    public function testResolvesCustomRangeFromFromTo(): void
    {
        $period = $this->resolver->resolve(new SnapshotQuery(null, '2026-01-05', '2026-01-20'));

        self::assertSame('2026-01-05', $period->getFrom()->format('Y-m-d'));
        self::assertSame('2026-01-20', $period->getTo()->format('Y-m-d'));
    }

    public function testThrowsWhenPresetAndCustomAreMixed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve(new SnapshotQuery('day', '2026-01-01', '2026-01-02'));
    }

    public function testThrowsOnInvalidDateFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve(new SnapshotQuery(null, '2026/01/01', '2026-01-02'));
    }

    public function testThrowsWhenFromIsGreaterThanTo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->resolver->resolve(new SnapshotQuery(null, '2026-01-03', '2026-01-02'));
    }
}
