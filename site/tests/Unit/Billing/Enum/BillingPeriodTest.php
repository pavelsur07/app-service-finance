<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Enum;

use App\Billing\Enum\BillingPeriod;
use PHPUnit\Framework\TestCase;

final class BillingPeriodTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('MONTH', BillingPeriod::MONTH->value);
        self::assertSame('YEAR', BillingPeriod::YEAR->value);
    }
}
