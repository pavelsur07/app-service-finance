<?php

declare(strict_types=1);

namespace App\Tests\Unit\Balance\Infrastructure;

use App\Balance\Application\BalancePeriodAction;
use PHPUnit\Framework\TestCase;

final class BalancePeriodActionTest extends TestCase
{
    public function testClosingStartsAtOpeningMonthAndAdvancesSequentially(): void
    {
        self::assertSame('2026-01-01', BalancePeriodAction::nextMonth('2026-01-19', null));
        self::assertSame('2027-01-01', BalancePeriodAction::nextMonth('2026-01-19', '2026-12-01'));
    }
}
