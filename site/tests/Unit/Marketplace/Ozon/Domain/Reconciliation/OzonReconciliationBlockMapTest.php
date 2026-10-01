<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Ozon\Domain\Reconciliation;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Ozon\Domain\OzonCostCategory;
use App\Marketplace\Ozon\Domain\Reconciliation\OzonReconciliationBlockMap;
use PHPUnit\Framework\TestCase;

final class OzonReconciliationBlockMapTest extends TestCase
{
    public function testEveryRecognizedCategoryFallsIntoACostBlock(): void
    {
        foreach (OzonCostCategory::recognizedCodes() as $code) {
            $block = OzonReconciliationBlockMap::forCategoryCode($code);

            self::assertTrue($block->isCostBlock(), $code);
            self::assertNotSame(OzonReconciliationBlock::UNRECOGNIZED, $block, sprintf('Category %s has an xlsx group without a block.', $code));
        }
    }

    public function testEveryXlsxGroupOfCatalogIsMapped(): void
    {
        $groups = array_unique(array_map(static fn (OzonCostCategory $c): string => $c->xlsxGroup, OzonCostCategory::all()));

        foreach ($groups as $group) {
            $codes = array_filter(
                OzonCostCategory::recognizedCodes(),
                static fn (string $code): bool => OzonCostCategory::findByCode($code)?->xlsxGroup === $group,
            );

            if ([] === $codes) {
                continue;
            }

            self::assertNotSame(
                OzonReconciliationBlock::UNRECOGNIZED,
                OzonReconciliationBlockMap::forCategoryCode(reset($codes)),
                $group,
            );
        }
    }

    public function testKnownCodes(): void
    {
        self::assertSame(OzonReconciliationBlock::COMMISSION, OzonReconciliationBlockMap::forCategoryCode('ozon_sale_commission'));
        self::assertSame(OzonReconciliationBlock::LOGISTICS, OzonReconciliationBlockMap::forCategoryCode('ozon_logistic_direct'));
    }

    public function testUnknownAndLegacyBucketAreUnrecognized(): void
    {
        self::assertSame(OzonReconciliationBlock::UNRECOGNIZED, OzonReconciliationBlockMap::forCategoryCode('ozon_unknown_52'));
        self::assertSame(OzonReconciliationBlock::UNRECOGNIZED, OzonReconciliationBlockMap::forCategoryCode('ozon_other_service'));
        self::assertSame(OzonReconciliationBlock::UNRECOGNIZED, OzonReconciliationBlockMap::forCategoryCode('no_such_code'));
    }

    public function testSalesAndReturnsAreNotCostBlocks(): void
    {
        self::assertFalse(OzonReconciliationBlock::SALES->isCostBlock());
        self::assertFalse(OzonReconciliationBlock::RETURNS->isCostBlock());
    }
}
