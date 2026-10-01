<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Application\Reconciliation;

use App\Marketplace\Ozon\Application\Reconciliation\OzonRawCoverage;
use PHPUnit\Framework\TestCase;

final class OzonRawCoverageTest extends TestCase
{
    public function testFullPastMonth(): void
    {
        self::assertSame(31, OzonRawCoverage::expectedDays(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), new \DateTimeImmutable('2026-12-01 10:00:00')));
    }

    public function testCurrentMonthStopsAtYesterdayMoscow(): void
    {
        // 01:30 UTC 11 октября = 04:30 МСК 11 октября, вчера — 10-е.
        self::assertSame(10, OzonRawCoverage::expectedDays(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), new \DateTimeImmutable('2026-10-11 01:30:00', new \DateTimeZone('UTC'))));
    }

    public function testMoscowMidnightShiftsYesterday(): void
    {
        // 22:30 UTC 10 октября = 01:30 МСК 11 октября: вчера уже 10-е, а не 9-е.
        self::assertSame(10, OzonRawCoverage::expectedDays(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), new \DateTimeImmutable('2026-10-10 22:30:00', new \DateTimeZone('UTC'))));
    }

    public function testStartIsClampedToEarliestSafeDay(): void
    {
        self::assertSame(23, OzonRawCoverage::expectedDays(new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable('2026-10-05')));
    }

    public function testPeriodBeforeByDayStartHasNoExpectedDays(): void
    {
        self::assertSame(0, OzonRawCoverage::expectedDays(new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-30'), new \DateTimeImmutable('2026-10-05')));
    }

    public function testFuturePeriodHasNoExpectedDays(): void
    {
        self::assertSame(0, OzonRawCoverage::expectedDays(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-31'), new \DateTimeImmutable('2026-10-05')));
    }
}
