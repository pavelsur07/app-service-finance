<?php

declare(strict_types=1);

namespace App\Tests\Unit\ServiceGateway\Application\Service;

use App\ServiceGateway\Application\Service\IsoWeeks;
use PHPUnit\Framework\TestCase;

final class IsoWeeksTest extends TestCase
{
    public function testWeeksOverlappingMonthWithDaysInsideIt(): void
    {
        $weeks = IsoWeeks::ofMonth('2026-11');

        self::assertSame(['2026-W44', '2026-W45', '2026-W46', '2026-W47', '2026-W48', '2026-W49'], array_map(static fn ($w): string => $w->key, $weeks));
        self::assertSame([1, 7, 7, 7, 7, 1], array_map(static fn ($w): int => $w->daysInMonth, $weeks));
        self::assertSame('2026-10-26', $weeks[0]->monday->format('Y-m-d'));
        self::assertSame('2026-11-01', $weeks[0]->sunday->format('Y-m-d'));
    }

    public function testYearBoundaryUsesIsoYear(): void
    {
        $keys = array_map(static fn ($w): string => $w->key, IsoWeeks::ofMonth('2027-01'));

        self::assertSame('2026-W53', $keys[0]);
        self::assertSame('2027-W01', $keys[1]);
        self::assertSame('2027-W04', end($keys)); // 25.01–31.01
    }

    public function testMonthStartingOnMonday(): void
    {
        $weeks = IsoWeeks::ofMonth('2027-02'); // 01.02.2027 — понедельник, 28 дней

        self::assertCount(4, $weeks);
        self::assertSame([7, 7, 7, 7], array_map(static fn ($w): int => $w->daysInMonth, $weeks));
    }

    public function testRejectsMalformedMonth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IsoWeeks::ofMonth('2026-13');
    }
}
