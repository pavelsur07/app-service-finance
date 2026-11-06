<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\Service\StockTurnoverPolicy;
use App\MarketplaceAnalytics\Enum\TurnoverBucket;
use PHPUnit\Framework\TestCase;

final class StockTurnoverPolicyTest extends TestCase
{
    private StockTurnoverPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new StockTurnoverPolicy();
    }

    public function testMeasureUsesPeriodAverage(): void
    {
        // 412 шт при 39 продажах за 30 дней: 1,3 в день, 412 / 1,3 = 316,9 → 316 дней → свыше 90.
        $m = $this->policy->measure(412, 39, 30);

        self::assertSame(1.3, $m->salesPerDay);
        self::assertSame(316, $m->daysOfCover);
        self::assertSame(TurnoverBucket::Frozen, $m->bucket);
    }

    public function testNoSalesGivesNullDaysAndNoSalesBucket(): void
    {
        $m = $this->policy->measure(5, 0, 30);

        self::assertSame(0.0, $m->salesPerDay);
        self::assertNull($m->daysOfCover);
        self::assertSame(TurnoverBucket::NoSales, $m->bucket);
    }

    public function testZeroStockWithSalesGivesZeroDays(): void
    {
        $m = $this->policy->measure(0, 10, 30);

        self::assertSame(0.33, $m->salesPerDay);
        self::assertSame(0, $m->daysOfCover);
        self::assertSame(TurnoverBucket::Fast, $m->bucket);
    }

    public function testExactBoundaryIsComputedWithoutFloatDrift(): void
    {
        // 30 продаж за 30 дней — ровно 1 в день; 90 шт — ровно 90 дней → «60–90», не «свыше 90».
        $m = $this->policy->measure(90, 30, 30);

        self::assertSame(90, $m->daysOfCover);
        self::assertSame(TurnoverBucket::Slow, $m->bucket);
    }

    public function testPeriodMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->policy->measure(1, 1, 0);
    }
}
