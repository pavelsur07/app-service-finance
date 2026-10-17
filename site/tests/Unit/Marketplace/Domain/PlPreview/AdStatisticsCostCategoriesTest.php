<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Domain\PlPreview;

use App\Marketplace\Domain\PlPreview\AdStatisticsCostCategories;
use App\Marketplace\Enum\MarketplaceType;
use PHPUnit\Framework\TestCase;

final class AdStatisticsCostCategoriesTest extends TestCase
{
    public function testOzonClicksAndWbPromotionOnly(): void
    {
        self::assertSame(['ozon_cpc'], AdStatisticsCostCategories::codesFor(MarketplaceType::OZON));
        self::assertSame(['wb_okazanie_uslug_wb_prodvizhenie'], AdStatisticsCostCategories::codesFor(MarketplaceType::WILDBERRIES));
        self::assertSame([], AdStatisticsCostCategories::codesFor(MarketplaceType::YANDEX_MARKET));
        self::assertSame([], AdStatisticsCostCategories::codesFor(MarketplaceType::SBER_MEGAMARKET));
    }

    public function testCoversIsPerMarketplace(): void
    {
        self::assertTrue(AdStatisticsCostCategories::covers(MarketplaceType::OZON, 'ozon_cpc'));
        self::assertFalse(AdStatisticsCostCategories::covers(MarketplaceType::WILDBERRIES, 'ozon_cpc'));
        // Подписки и прочее продвижение статистикой не покрыты.
        self::assertFalse(AdStatisticsCostCategories::covers(MarketplaceType::OZON, 'ozon_premium_promotion'));
        self::assertFalse(AdStatisticsCostCategories::covers(MarketplaceType::WILDBERRIES, 'wb_okazanie_uslug_wb_media'));
    }
}
