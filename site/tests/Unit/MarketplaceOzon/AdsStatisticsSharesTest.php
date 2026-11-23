<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceOzon;

use App\MarketplaceOzon\Domain\AdsStatisticsShares;
use PHPUnit\Framework\TestCase;

final class AdsStatisticsSharesTest extends TestCase
{
    public function testSpendBySkuParsesCsvDecimalsAndSumsCampaigns(): void
    {
        self::assertSame([11 => 115219, 22 => 100050], AdsStatisticsShares::spendBySku([
            ['sku' => '11', 'Расход, ₽, с НДС' => '1 152,19'],
            ['sku' => '22', 'Расход, ₽, с НДС' => '1000,00'],
            ['sku' => '22', 'расход, ₽, с ндс' => '0,50'],
            ['sku' => '33', 'Расход, ₽, с НДС' => '0,00'],
            ['sku' => '44', 'Расход, ₽, с НДС' => 'n/a'],
            ['sku' => '', 'Расход, ₽, с НДС' => '5,00'],
        ]));
    }

    public function testSplitKeepsTotalWithLargestRemainder(): void
    {
        self::assertSame(['a' => -34, 'b' => -33, 'c' => -33], AdsStatisticsShares::split(-100, ['a' => 1, 'b' => 1, 'c' => 1]));
        self::assertSame(['' => 25, 'x' => 75], AdsStatisticsShares::split(100, ['x' => 3, '' => 1]));
        self::assertSame(['b' => 1], AdsStatisticsShares::split(1, ['a' => 1, 'b' => 2]));
        self::assertSame([], AdsStatisticsShares::split(0, ['a' => 1]));
        self::assertSame(['' => 7], AdsStatisticsShares::split(7, []));
    }
}
