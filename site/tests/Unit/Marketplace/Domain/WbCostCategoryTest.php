<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Domain;

use App\Ingestion\Application\Source\Wildberries\WbDeductionCategory;
use App\Marketplace\Domain\WbCostCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WbCostCategoryTest extends TestCase
{
    public function testByCodeContainsKnownCategories(): void
    {
        $byCode = WbCostCategory::byCode();

        $this->assertArrayHasKey('commission', $byCode);
        $this->assertArrayHasKey('logistics_delivery', $byCode);
        $this->assertArrayHasKey('warehouse_logistics', $byCode);
        $this->assertArrayHasKey('logistics_correction', $byCode);
        $this->assertArrayHasKey('wb_loyalty_discount_compensation', $byCode);
        $this->assertArrayHasKey('wb_dobrovolnaya_vyplata_za_tovary', $byCode);
        $this->assertArrayHasKey('wb_warehouse_disposal', $byCode);
        $this->assertArrayHasKey('product_processing', $byCode);
    }

    public function testUnitBucketBelongsToKnownSet(): void
    {
        $allowed = ['commission', 'logistics', 'other'];

        foreach (WbCostCategory::all() as $category) {
            $this->assertContains($category->unitBucket, $allowed);
        }
    }

    #[DataProvider('knownDeductionReasonProvider')]
    public function testLegacyAndCanonicalDeductionCategoriesStayInParity(string $reason): void
    {
        $legacy = WbCostCategory::forDeductionName($reason);
        $canonical = WbDeductionCategory::resolve($reason);

        self::assertNotNull($legacy);
        self::assertNotNull($canonical);
        self::assertSame($legacy->code, $canonical->code);
        self::assertSame($legacy->name, $canonical->label);
        self::assertSame($legacy->breakdownGroup, $canonical->group);
    }

    /** @return iterable<string, array{string}> */
    public static function knownDeductionReasonProvider(): iterable
    {
        yield 'voluntary goods payment' => ['  Добровольная   выплата за товары, документ №123  '];
        yield 'warehouse disposal' => ['Отчёт об утилизированном товаре (по складу) за июль 2026'];
    }
}
