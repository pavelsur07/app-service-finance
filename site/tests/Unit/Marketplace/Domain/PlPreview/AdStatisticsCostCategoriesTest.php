<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Domain\PlPreview;

use App\Marketplace\Domain\PlPreview\AdStatisticsCostCategories;
use App\Marketplace\Enum\AdSpendKind;
use App\Marketplace\Enum\MarketplaceType;
use PHPUnit\Framework\TestCase;

final class AdStatisticsCostCategoriesTest extends TestCase
{
    public function testOzonClicksAndPayPerOrderAndWbPromotionOnly(): void
    {
        self::assertSame(
            ['ozon_cpc' => AdSpendKind::CLICKS, 'ozon_marketing_action' => AdSpendKind::PAY_PER_ORDER],
            AdStatisticsCostCategories::ledgerCategories(MarketplaceType::OZON),
        );
        self::assertSame(['wb_okazanie_uslug_wb_prodvizhenie' => null], AdStatisticsCostCategories::ledgerCategories(MarketplaceType::WILDBERRIES));
        self::assertSame([], AdStatisticsCostCategories::ledgerCategories(MarketplaceType::YANDEX_MARKET));
        self::assertSame([], AdStatisticsCostCategories::ledgerCategories(MarketplaceType::SBER_MEGAMARKET));
    }

    public function testPayPerOrderIsNotExcludedWholeFromListingReports(): void
    {
        self::assertNotContains('ozon_marketing_action', AdStatisticsCostCategories::codes());
        self::assertContains('ozon_marketing_action', AdStatisticsCostCategories::allCodes());
        self::assertSame('ozon_marketing_action', AdStatisticsCostCategories::payPerOrderCode());
    }
}
