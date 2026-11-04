<?php

declare(strict_types=1);

namespace App\Tests\Unit\Budget;

use App\Budget\Domain\BudgetFillMath;
use App\Finance\Enum\PLFlow;
use PHPUnit\Framework\TestCase;

final class BudgetFillMathTest extends TestCase
{
    public function testDistributePutsKopeckRemainderIntoDecember(): void
    {
        $parts = BudgetFillMath::distribute('1000.00', 12);

        self::assertCount(12, $parts);
        self::assertSame('83.33', $parts[0]);
        self::assertSame('83.33', $parts[10]);
        self::assertSame('83.37', $parts[11]);
        self::assertSame('1000.00', array_reduce($parts, static fn (string $sum, string $p): string => bcadd($sum, $p, 2), '0'));
    }

    public function testDistributeNegativeKeepsSignAndTotal(): void
    {
        $parts = BudgetFillMath::distribute('-100.00', 12);

        self::assertSame('-8.33', $parts[0]);
        self::assertSame('-8.37', $parts[11]);
    }

    public function testAverageRoundsHalfAwayFromZero(): void
    {
        self::assertSame('33.34', BudgetFillMath::average(['100.00', '0.01', '0.01'], 3));
        self::assertSame('-0.01', BudgetFillMath::average(['-0.01', '-0.01', '0.00'], 3));
        self::assertSame('50.00', BudgetFillMath::average(['100.00'], 2), 'месяц без факта считается нулём');
        self::assertSame('0.00', BudgetFillMath::average([], 3));
    }

    public function testPercentOf(): void
    {
        self::assertSame('125.00', BudgetFillMath::percentOf('1000.00', '12.5'));
        self::assertSame('0.03', BudgetFillMath::percentOf('0.50', '5'));
        self::assertSame('-50.00', BudgetFillMath::percentOf('-1000.00', '5'));
    }

    public function testSignByFlow(): void
    {
        self::assertSame('-60.00', BudgetFillMath::signByFlow('60.00', PLFlow::EXPENSE));
        self::assertSame('-60.00', BudgetFillMath::signByFlow('-60.00', PLFlow::EXPENSE));
        self::assertSame('60.00', BudgetFillMath::signByFlow('-60.00', PLFlow::INCOME));
        self::assertSame('-60.00', BudgetFillMath::signByFlow('-60.00', PLFlow::NONE));
        self::assertSame('0.00', BudgetFillMath::signByFlow('0.00', PLFlow::EXPENSE));
    }

    public function testFromInputFollowsGridInputConvention(): void
    {
        self::assertSame('-1200.00', BudgetFillMath::fromInput('1200', PLFlow::EXPENSE));
        self::assertSame('1200.00', BudgetFillMath::fromInput('-1200', PLFlow::EXPENSE));
        self::assertSame('-1200.00', BudgetFillMath::fromInput('-1200', PLFlow::INCOME));
        self::assertSame('-1200.00', BudgetFillMath::fromInput('-1200', PLFlow::NONE));
    }

    public function testIsPercentAndAmountValidation(): void
    {
        self::assertTrue(BudgetFillMath::isPercent('12.5'));
        self::assertTrue(BudgetFillMath::isPercent('100'));
        self::assertFalse(BudgetFillMath::isPercent('-1'));
        self::assertFalse(BudgetFillMath::isPercent('1000.1'));
        self::assertFalse(BudgetFillMath::isPercent('abc'));
    }
}
