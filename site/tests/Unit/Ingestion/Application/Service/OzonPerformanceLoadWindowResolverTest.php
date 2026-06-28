<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Service;

use App\Ingestion\Application\Service\OzonPerformanceLoadWindowResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OzonPerformanceLoadWindowResolverTest extends TestCase
{
    public function testRollingWindowUsesConfiguredDaysBackUntilYesterday(): void
    {
        $resolver = new OzonPerformanceLoadWindowResolver(new MockClock('2026-06-28 10:00:00 UTC'));

        $window = $resolver->resolve(OzonPerformanceLoadWindowResolver::WINDOW_ROLLING, 14);

        self::assertSame('2026-06-14', $window->from->format('Y-m-d'));
        self::assertSame('2026-06-27', $window->to->format('Y-m-d'));
        self::assertSame('last-14-days', $window->label);
    }

    public function testMonthToDateStartsFromYesterdayMonthStart(): void
    {
        $resolver = new OzonPerformanceLoadWindowResolver(new MockClock('2026-06-28 10:00:00 UTC'));

        $window = $resolver->resolve(OzonPerformanceLoadWindowResolver::WINDOW_MONTH_TO_DATE, 14);

        self::assertSame('2026-06-01', $window->from->format('Y-m-d'));
        self::assertSame('2026-06-27', $window->to->format('Y-m-d'));
        self::assertSame('month-to-date', $window->label);
    }

    public function testMonthToDateOnFirstDayUsesPreviousMonth(): void
    {
        $resolver = new OzonPerformanceLoadWindowResolver(new MockClock('2026-07-01 10:00:00 UTC'));

        $window = $resolver->resolve(OzonPerformanceLoadWindowResolver::WINDOW_MONTH_TO_DATE, 14);

        self::assertSame('2026-06-01', $window->from->format('Y-m-d'));
        self::assertSame('2026-06-30', $window->to->format('Y-m-d'));
    }

    public function testRejectsUnknownWindow(): void
    {
        $resolver = new OzonPerformanceLoadWindowResolver(new MockClock('2026-06-28 10:00:00 UTC'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid --window');

        $resolver->resolve('unknown', 14);
    }
}
