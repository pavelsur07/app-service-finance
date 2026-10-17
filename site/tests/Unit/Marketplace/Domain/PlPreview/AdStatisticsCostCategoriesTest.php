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
        self::assertSame('ozon_cpc', AdStatisticsCostCategories::codeFor(MarketplaceType::OZON));
        self::assertSame('wb_okazanie_uslug_wb_prodvizhenie', AdStatisticsCostCategories::codeFor(MarketplaceType::WILDBERRIES));
        self::assertNull(AdStatisticsCostCategories::codeFor(MarketplaceType::YANDEX_MARKET));
        self::assertNull(AdStatisticsCostCategories::codeFor(MarketplaceType::SBER_MEGAMARKET));
    }
}
