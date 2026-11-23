<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceWb;

use App\MarketplaceWb\Application\Action\TranslateWbDayAction;
use PHPUnit\Framework\TestCase;

final class WbListingAllocationTest extends TestCase
{
    private const SIZES = [['id' => 'l-s', 'size' => 'S'], ['id' => 'l-m', 'size' => 'М'], ['id' => 'l-l', 'size' => 'L']];

    public function testKnownSizeGoesToItsListingEvenWithCyrillicLookalike(): void
    {
        self::assertSame(['l-m' => -1000], TranslateWbDayAction::allocate(self::SIZES, 'M', -1000, []), 'латинская M = кириллическая М');
        self::assertSame(['l-s' => -1000], TranslateWbDayAction::allocate(self::SIZES, ' s ', -1000, []));
    }

    public function testKnownSizeWithoutListingStaysWithoutSkuUnlessArticleHasOneListing(): void
    {
        self::assertSame([], TranslateWbDayAction::allocate(self::SIZES, 'XXL', -1000, []));
        self::assertSame(['l-s' => -1000], TranslateWbDayAction::allocate([self::SIZES[0]], 'XXL', -1000, []));
    }

    public function testUnknownSizeIsSplitAcrossArticleSizesBySalesOfTheDay(): void
    {
        // продано S — 1, L — 3; M без продаж ничего не получает; Σ = сумма до копейки
        self::assertSame(['l-l' => -751, 'l-s' => -250], TranslateWbDayAction::allocate(self::SIZES, null, -1001, ['l-s' => 1, 'l-l' => 3, 'other' => 9]));
    }

    public function testUnknownSizeWithoutSalesIsSplitEqually(): void
    {
        $parts = TranslateWbDayAction::allocate(self::SIZES, 'UNKNOWN', -1000, []);
        self::assertSame(-1000, array_sum($parts));
        self::assertEqualsCanonicalizing([-334, -333, -333], array_values($parts));
    }

    public function testNoListingsMeansWithoutSku(): void
    {
        self::assertSame([], TranslateWbDayAction::allocate([], null, -1000, []));
    }
}
