<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Domain\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testDaysAndPreviousPeriodAreCalculated(): void
    {
        $period = new Period(
            new \DateTimeImmutable('2026-01-10'),
            new \DateTimeImmutable('2026-01-15'),
        );

        self::assertSame(6, $period->days());

        $previous = $period->prevPeriod();

        self::assertSame('2026-01-04', $previous->getFrom()->format('Y-m-d'));
        self::assertSame('2026-01-09', $previous->getTo()->format('Y-m-d'));
    }
}
