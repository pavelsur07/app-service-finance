<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Domain;

use App\Inventory\Domain\StockSnapshotFreshnessPolicy;
use PHPUnit\Framework\TestCase;

final class StockSnapshotFreshnessPolicyTest extends TestCase
{
    public function testDefaultThresholdIsTwoDays(): void
    {
        self::assertSame(2, (new StockSnapshotFreshnessPolicy())->maxAgeDays());
    }

    public function testEarliestAcceptableDateIsReportDateMinusThreshold(): void
    {
        $policy = new StockSnapshotFreshnessPolicy(2);

        self::assertSame(
            '2026-09-04',
            $policy->earliestAcceptableDate(new \DateTimeImmutable('2026-09-06 14:33:21'))->format('Y-m-d'),
        );
    }

    public function testSnapshotOnReportDateIsFresh(): void
    {
        $policy = new StockSnapshotFreshnessPolicy(2);

        self::assertFalse($policy->isStale(
            new \DateTimeImmutable('2026-09-06'),
            new \DateTimeImmutable('2026-09-06'),
        ));
    }

    public function testSnapshotExactlyOnThresholdIsFresh(): void
    {
        $policy = new StockSnapshotFreshnessPolicy(2);

        self::assertFalse($policy->isStale(
            new \DateTimeImmutable('2026-09-04'),
            new \DateTimeImmutable('2026-09-06'),
        ));
    }

    public function testSnapshotOneDayBeyondThresholdIsStale(): void
    {
        $policy = new StockSnapshotFreshnessPolicy(2);

        self::assertTrue($policy->isStale(
            new \DateTimeImmutable('2026-09-03'),
            new \DateTimeImmutable('2026-09-06'),
        ));
    }

    public function testTimeOfDayDoesNotAffectTheVerdictOnTheThresholdDay(): void
    {
        $policy = new StockSnapshotFreshnessPolicy(2);

        self::assertFalse($policy->isStale(
            new \DateTimeImmutable('2026-09-04 00:00:01'),
            new \DateTimeImmutable('2026-09-06 23:59:59'),
        ));
        self::assertFalse($policy->isStale(
            new \DateTimeImmutable('2026-09-04 23:59:59'),
            new \DateTimeImmutable('2026-09-06 00:00:01'),
        ));
    }

    public function testProductionCaseIsStale(): void
    {
        // Компания 5d26f9aa на PROD: последний снапшот 2026-05-23 при отчёте на 2026-09-06.
        $policy = new StockSnapshotFreshnessPolicy();

        self::assertTrue($policy->isStale(
            new \DateTimeImmutable('2026-05-23'),
            new \DateTimeImmutable('2026-09-06'),
        ));
    }

    public function testReferenceDateIsClampedToTodayWhenPeriodEndsInTheFuture(): void
    {
        // Регрессия PROD: пресет «текущий месяц» отдаёт последний день месяца, то есть
        // дату в будущем. Без ограничения сегодняшним днём свежий снапшот считался бы
        // просроченным, и отчёт оставался бы вовсе без остатков.
        $policy = new StockSnapshotFreshnessPolicy();

        self::assertSame(
            '2026-09-06',
            $policy->referenceDate(
                new \DateTimeImmutable('2026-09-30'),
                new \DateTimeImmutable('2026-09-06'),
            )->format('Y-m-d'),
        );
    }

    public function testReferenceDateStaysAtReportDateForPastPeriod(): void
    {
        // Для периода в прошлом уместен снапшот того времени, а не сегодняшний.
        $policy = new StockSnapshotFreshnessPolicy();

        self::assertSame(
            '2026-05-31',
            $policy->referenceDate(
                new \DateTimeImmutable('2026-05-31'),
                new \DateTimeImmutable('2026-09-06'),
            )->format('Y-m-d'),
        );
    }

    public function testTodaysSnapshotIsFreshForPeriodEndingInTheFuture(): void
    {
        $policy = new StockSnapshotFreshnessPolicy();
        $today = new \DateTimeImmutable('2026-09-06');
        $reference = $policy->referenceDate(new \DateTimeImmutable('2026-09-30'), $today);

        self::assertFalse(
            $policy->isStale(new \DateTimeImmutable('2026-09-06'), $reference),
            'Сегодняшний снапшот не может быть протухшим только потому, что период отчёта кончается в будущем.',
        );
    }

    public function testDeadPipelineIsStillStaleWithClamping(): void
    {
        // Ограничение опорной даты не должно ослабить исходную защиту.
        $policy = new StockSnapshotFreshnessPolicy();
        $today = new \DateTimeImmutable('2026-09-06');
        $reference = $policy->referenceDate(new \DateTimeImmutable('2026-09-30'), $today);

        self::assertTrue($policy->isStale(new \DateTimeImmutable('2026-05-23'), $reference));
    }

    public function testCalendarDatesAreComparedRegardlessOfTimezone(): void
    {
        // Полночь в разных часовых поясах — разные моменты времени, поэтому
        // календарно более поздняя дата может оказаться «меньше» по сравнению
        // объектов. Политика оперирует днями, а не моментами.
        $policy = new StockSnapshotFreshnessPolicy();

        $reference = $policy->referenceDate(
            new \DateTimeImmutable('2026-09-07 00:00', new \DateTimeZone('+14:00')),
            new \DateTimeImmutable('2026-09-06 12:00', new \DateTimeZone('-12:00')),
        );

        self::assertSame('2026-09-06', $reference->format('Y-m-d'));
    }

    public function testEqualReportDateAndTodayGiveThatSameDate(): void
    {
        $policy = new StockSnapshotFreshnessPolicy();

        self::assertSame(
            '2026-09-06',
            $policy->referenceDate(
                new \DateTimeImmutable('2026-09-06 23:59:59'),
                new \DateTimeImmutable('2026-09-06 00:00:01'),
            )->format('Y-m-d'),
        );
    }

    public function testZeroThresholdAcceptsOnlyTheReportDateItself(): void
    {
        $policy = new StockSnapshotFreshnessPolicy(0);

        self::assertFalse($policy->isStale(new \DateTimeImmutable('2026-09-06'), new \DateTimeImmutable('2026-09-06')));
        self::assertTrue($policy->isStale(new \DateTimeImmutable('2026-09-05'), new \DateTimeImmutable('2026-09-06')));
    }

    public function testNegativeThresholdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StockSnapshotFreshnessPolicy(-1);
    }
}
