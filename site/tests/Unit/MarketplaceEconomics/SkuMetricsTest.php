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

    public function testLevelsAreNetOfVatAndPercentsFromRevenueBeforeSpp(): void
    {
        $m = new SkuMetrics();
        $m->add('sale', L::REVENUE, 100000, 1);
        $m->add('cost_price', L::COST_PRICE, -30000, 1);
        $m->add('commission', L::MD1, -10000, 0);
        $m->add('ads_cpc', L::MD2, -5000, 0);
        $m->add('overhead_share', L::OVERHEAD, -1000, 0);
        $m->add('spp', L::INFO, 15000, 0);
        $m->add('coinvestment', L::INFO, 1000, 0);
        $m->add('ads_settlement', L::INFO, -7000, 0);
        $m->vat = -4000;

        self::assertSame([16000, 84000, 96000], [$m->spp, $m->revenueWithSpp(), $m->revenueNet()]);
        self::assertSame([66000, 56000, 51000, 50000], [$m->gross(), $m->md1(), $m->md2(), $m->full()]);
        self::assertSame([56.0, 51.0, 5.0], [$m->percentOfRevenue($m->md1()), $m->percentOfRevenue($m->md2()), $m->drr()]);
    }

    public function testMergeAddsVat(): void
    {
        $a = new SkuMetrics();
        $a->vat = -500;
        $b = new SkuMetrics();
        $b->vat = 200;
        $a->merge($b);

        self::assertSame(-300, $a->vat);
    }
}
