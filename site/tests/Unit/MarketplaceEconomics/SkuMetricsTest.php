<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceEconomics;

use App\MarketplaceEconomics\Domain\EconomicsLevel as L;
use App\MarketplaceEconomics\Domain\SkuMetrics;
use PHPUnit\Framework\TestCase;

final class SkuMetricsTest extends TestCase
{
    public function testMeasuresFallIntoColumnsAndLevelsCascade(): void
    {
        $m = new SkuMetrics();
        foreach ([
            ['sale', L::REVENUE, 100000, 2], ['return', L::REVENUE, -20000, 1], ['cost_price', L::COST_PRICE, -30000, 1],
            ['commission_brand', L::MD1, -1000, 0], ['logistics_to_last_mile', L::MD1, -2000, 0], ['logistics_back_pvz', L::MD1, -300, 0],
            ['acquiring', L::MD1, -400, 0], ['storage', L::MD1, -500, 0], ['ads_cpc', L::MD2, -6000, 0],
            ['overhead_share', L::OVERHEAD, -700, 0], ['spp', L::INFO, 9000, 0],
        ] as [$measure, $level, $amount, $qty]) {
            $m->add($measure, $level, $amount, $qty);
        }

        self::assertSame([2, 1, -1000, -2000, -300, -400, -500, 9000], [$m->salesQty, $m->returnsQty, $m->commission, $m->logisticsTo, $m->logisticsBack, $m->acquiring, $m->otherMd1, $m->spp]);
        self::assertSame([80000, 50000, 45800, 39800, 39100], [$m->revenue, $m->gross(), $m->md1(), $m->md2(), $m->full()]);
        self::assertSame([7.5, 130.3], [$m->drr(), $m->roi()]);
        self::assertNull((new SkuMetrics())->percentOfRevenue(100));
    }
}
