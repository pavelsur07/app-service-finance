<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class WbFinancialReportPeriodResolverTest extends TestCase
{
    public function testYesterdayUsesMoscowBusinessDate(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-10 00:30:00 UTC'));

        self::assertSame('2026-05-09 00:00:00 Europe/Moscow', $resolver->yesterday()->format('Y-m-d H:i:s e'));
    }

    public function testCurrentYearStartUsesCurrentBusinessYear(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-01-01 00:30:00 UTC'));

        self::assertSame('2026-01-01 00:00:00 Europe/Moscow', $resolver->currentYearStart()->format('Y-m-d H:i:s e'));
    }

    public function testCurrentYearStartUsesCurrentBusinessYearForRegularDate(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 Europe/Moscow'));

        self::assertSame('2026-01-01 00:00:00 Europe/Moscow', $resolver->currentYearStart()->format('Y-m-d H:i:s e'));
    }

    public function testLast14DaysReturnsFourteenDatesIncludingYesterday(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 Europe/Moscow'));

        $days = $resolver->last14Days();

        self::assertCount(14, $days);
        self::assertSame('2026-05-06', $days[0]->format('Y-m-d'));
        self::assertSame('2026-05-19', $days[13]->format('Y-m-d'));
    }

    public function testDaysBetweenReturnsInclusiveNormalizedDayRange(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 UTC'));

        $days = $resolver->daysBetween(
            new \DateTimeImmutable('2026-05-03 23:59:59 UTC'),
            new \DateTimeImmutable('2026-05-05 01:00:00 Europe/Moscow'),
        );

        self::assertSame(['2026-05-04', '2026-05-05'], array_map(
            static fn (\DateTimeImmutable $day): string => $day->format('Y-m-d'),
            $days,
        ));
    }

    public function testDaysBetweenThrowsWhenFromGreaterThanTo(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 UTC'));

        $this->expectException(DomainException::class);
        $resolver->daysBetween(
            new \DateTimeImmutable('2026-05-10 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-09 00:00:00 Europe/Moscow'),
        );
    }

    public function testNormalizeBusinessDateParsesValidDate(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 UTC'));

        $date = $resolver->normalizeBusinessDate(' 2026-02-03 ');

        self::assertSame('2026-02-03 00:00:00 Europe/Moscow', $date->format('Y-m-d H:i:s e'));
    }

    public function testNormalizeBusinessDateThrowsForEmptyDate(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 UTC'));

        $this->expectException(InvalidArgumentException::class);
        $resolver->normalizeBusinessDate('   ');
    }

    public function testNormalizeBusinessDateThrowsForInvalidDate(): void
    {
        $resolver = new WbFinancialReportPeriodResolver(new MockClock('2026-05-20 12:00:00 UTC'));

        $this->expectException(DomainException::class);
        $resolver->normalizeBusinessDate('2026-02-30');
    }
}
