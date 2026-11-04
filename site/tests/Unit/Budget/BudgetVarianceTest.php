<?php

declare(strict_types=1);

namespace App\Tests\Unit\Budget;

use App\Budget\Domain\BudgetVariance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetVarianceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool, string, ?string, bool}>
     */
    public static function cases(): iterable
    {
        // plan, fact, money, diff, percent, significant
        yield 'income above plan by 15%' => ['1000.00', '1150.00', true, '150.00', '15.0', true];
        yield 'within threshold' => ['1000.00', '1100.00', true, '100.00', '10.0', false];
        yield 'just over threshold' => ['1000.00', '899.99', true, '-100.01', '-10.0', true];
        yield 'expense overspent is negative' => ['-300.00', '-360.00', true, '-60.00', '-20.0', true];
        yield 'expense saved is positive' => ['-300.00', '-240.00', true, '60.00', '20.0', true];
        yield 'no plan, fact exists' => ['0.00', '50.00', true, '50.00', null, true];
        yield 'no plan, no fact' => ['0.00', '0.00', true, '0.00', null, false];
        yield 'kpi: diff only' => ['15.5000', '12.0000', false, '-3.5000', null, false];
    }

    #[DataProvider('cases')]
    public function testVariance(string $plan, string $fact, bool $money, string $diff, ?string $percent, bool $significant): void
    {
        $variance = BudgetVariance::of($plan, $fact, $money);

        self::assertSame($plan, $variance->plan);
        self::assertSame($fact, $variance->fact);
        self::assertSame($diff, $variance->diff);
        self::assertSame($percent, $variance->percent);
        self::assertSame($significant, $variance->significant);
    }
}
