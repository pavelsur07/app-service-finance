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
