<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\Report\CashflowGroupCalendar;
use App\Shared\Service\ReportPeriodCalendar;
use PHPUnit\Framework\TestCase;

final class CashflowGroupCalendarTest extends TestCase
{
    public function testNovemberStartsOnSundayAndEndsOnMonday(): void
    {
        $calendar = CashflowGroupCalendar::forRange(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30'), 'week');

        self::assertSame('week', $calendar->group);
        self::assertSame('week', $calendar->requestedGroup);
        self::assertCount(6, $calendar->periods);
        $first = $calendar->periods[0];
        self::assertSame('01.11-01.11', $first['label']);
        self::assertSame('2026-11-01', $first['start']->format('Y-m-d'));
        self::assertSame('2026-11-01', $first['end']->format('Y-m-d'));
        self::assertSame('2026-10-26', $calendar->bucketKey($first['start']));
        self::assertSame('02.11-08.11', $calendar->periods[1]['label']);
        self::assertSame('30.11-30.11', $calendar->periods[5]['label']);
    }

    public function testPartialMonthAndQuarterKeepTheirOwnKeyAndCutBothEdges(): void
    {
        $from = new \DateTimeImmutable('2026-11-14');
        $to = new \DateTimeImmutable('2026-12-09');
        $months = CashflowGroupCalendar::forRange($from, $to, 'month');
        self::assertSame(['11.2026', '12.2026'], array_column($months->periods, 'label'));
        self::assertSame('2026-11-14', $months->periods[0]['start']->format('Y-m-d'));
        self::assertSame('2026-11-01', $months->bucketKey($from));
        self::assertSame('2026-12-09', $months->periods[1]['end']->format('Y-m-d'));

        $quarters = CashflowGroupCalendar::forRange($from, $to, 'quarter');
        self::assertSame(['4 кв. 2026'], array_column($quarters->periods, 'label'));
        self::assertSame('2026-11-14', $quarters->periods[0]['start']->format('Y-m-d'));
        self::assertSame('2026-12-09', $quarters->periods[0]['end']->format('Y-m-d'));
        self::assertSame('2026-10-01', $quarters->bucketKey($to));
    }

    public function testTwoYearsOfWeeksBecomeTwentyFourMonths(): void
    {
        $calendar = CashflowGroupCalendar::forRange(new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2026-12-31'), 'week');

        self::assertSame('week', $calendar->requestedGroup);
        self::assertSame('month', $calendar->group);
        self::assertCount(24, $calendar->periods);
        self::assertFalse($calendar->tooLarge);
    }

    public function testExactlyThirtySixWeeksKeepWeeklyGrouping(): void
    {
        $from = new \DateTimeImmutable('2026-01-05');
        $calendar = CashflowGroupCalendar::forRange($from, $from->modify('+36 weeks -1 day'), 'week');
        self::assertSame('week', $calendar->group);
        self::assertCount(36, $calendar->periods);
    }

    public function testMonthBoundariesUseCivilDatesRegardlessOfInputTimezone(): void
    {
        $zone = new \DateTimeZone('America/Los_Angeles');
        $from = new \DateTimeImmutable('2026-10-01', $zone);
        $to = new \DateTimeImmutable('2026-10-31', $zone);
        $calendar = CashflowGroupCalendar::forRange($from, $to, 'month');
        self::assertCount(1, $calendar->periods);
        self::assertSame('2026-10-31', $calendar->periods[0]['end']->format('Y-m-d'));
    }

    public function testCompanyCalendarKeepsRollingWeeksAndExistingQuarterLabels(): void
    {
        $from = new \DateTimeImmutable('2026-11-01');
        $to = new \DateTimeImmutable('2026-11-30');
        $weeks = ReportPeriodCalendar::periods($from, $to, 'week');
        self::assertSame('01.11-07.11', $weeks[0]['label']);
        $quarters = ReportPeriodCalendar::periods($from, $to, 'quarter');
        self::assertSame('Q4 2026', $quarters[0]['label']);
        self::assertSame('2026-10-01', $quarters[0]['start']->format('Y-m-d'));
    }

    public function testTenCalendarYearsOfQuartersBecomeTenYears(): void
    {
        $from = new \DateTimeImmutable('2000-04-15');
        $to = new \DateTimeImmutable('2009-11-12');
        $calendar = CashflowGroupCalendar::forRange($from, $to, 'quarter');
        self::assertSame('year', $calendar->group);
        self::assertSame('quarter', $calendar->requestedGroup);
        self::assertCount(10, $calendar->periods);
        self::assertSame('2000-04-15', $calendar->periods[0]['start']->format('Y-m-d'));
        self::assertSame('2009-11-12', $calendar->periods[9]['end']->format('Y-m-d'));
        self::assertSame('2009-01-01', $calendar->bucketKey($to));
    }

    public function testYearCanBeRequestedEvenWhenTheRangeCoversJustTwoDays(): void
    {
        $calendar = CashflowGroupCalendar::forRange(new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2027-01-01'), 'year');
        self::assertSame('year', $calendar->requestedGroup);
        self::assertSame('year', $calendar->group);
        self::assertFalse($calendar->tooLarge);
        self::assertSame(['2026', '2027'], array_column($calendar->periods, 'label'));
    }

    public function testThirtySixCalendarYearsFitAndTheFirstDayOfTheNextYearExceedsTheLimit(): void
    {
        $from = new \DateTimeImmutable('2000-12-31');
        $calendar = CashflowGroupCalendar::forRange($from, new \DateTimeImmutable('2035-12-31'), 'year');
        self::assertCount(36, $calendar->periods);
        self::assertFalse($calendar->tooLarge);

        $longRange = CashflowGroupCalendar::forRange($from, new \DateTimeImmutable('2036-01-01'), 'week');
        self::assertTrue($longRange->tooLarge);
        self::assertSame('year', $longRange->group);
        self::assertSame('week', $longRange->requestedGroup);
        self::assertSame([], $longRange->periods);
    }
}
