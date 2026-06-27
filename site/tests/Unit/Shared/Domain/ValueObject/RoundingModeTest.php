<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\ValueObject;

use App\Shared\Domain\ValueObject\RoundingMode;
use PHPUnit\Framework\TestCase;

final class RoundingModeTest extends TestCase
{
    public function testHalfUpRoundsAwayFromZeroOnTie(): void
    {
        self::assertSame('2', RoundingMode::HALF_UP->roundToInteger('1.5'));
        self::assertSame('3', RoundingMode::HALF_UP->roundToInteger('2.5'));
        self::assertSame('-2', RoundingMode::HALF_UP->roundToInteger('-1.5'));
    }

    public function testHalfUpBelowAndAboveTie(): void
    {
        self::assertSame('1', RoundingMode::HALF_UP->roundToInteger('1.4'));
        self::assertSame('2', RoundingMode::HALF_UP->roundToInteger('1.6'));
        self::assertSame('-1', RoundingMode::HALF_UP->roundToInteger('-1.4'));
    }

    public function testHalfEvenRoundsToEvenOnTie(): void
    {
        self::assertSame('0', RoundingMode::HALF_EVEN->roundToInteger('0.5'));
        self::assertSame('2', RoundingMode::HALF_EVEN->roundToInteger('1.5'));
        self::assertSame('2', RoundingMode::HALF_EVEN->roundToInteger('2.5'));
        self::assertSame('4', RoundingMode::HALF_EVEN->roundToInteger('3.5'));
        self::assertSame('-2', RoundingMode::HALF_EVEN->roundToInteger('-2.5'));
    }

    public function testHalfEvenNonTie(): void
    {
        self::assertSame('3', RoundingMode::HALF_EVEN->roundToInteger('2.6'));
        self::assertSame('2', RoundingMode::HALF_EVEN->roundToInteger('2.4'));
    }

    public function testIntegerInputUnchanged(): void
    {
        self::assertSame('5', RoundingMode::HALF_UP->roundToInteger('5'));
        self::assertSame('0', RoundingMode::HALF_UP->roundToInteger('0'));
        self::assertSame('-7', RoundingMode::HALF_EVEN->roundToInteger('-7'));
    }
}
