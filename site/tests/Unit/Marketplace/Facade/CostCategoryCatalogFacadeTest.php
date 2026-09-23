<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Facade;

use App\Marketplace\Domain\OzonCostCategory;
use App\Marketplace\Facade\CostCategoryCatalogFacade;
use App\Marketplace\Wildberries\Domain\WbCostCategory;
use PHPUnit\Framework\TestCase;

final class CostCategoryCatalogFacadeTest extends TestCase
{
    public function testOzonGroupsMirrorCatalog(): void
    {
        $groups = (new CostCategoryCatalogFacade())->ozonCostCategoryGroups();

        self::assertCount(count(OzonCostCategory::all()), $groups);
        foreach (OzonCostCategory::all() as $category) {
            self::assertSame($category->widgetGroup, $groups[$category->code]->widgetGroup);
            self::assertSame($category->xlsxGroup, $groups[$category->code]->breakdownGroup);
            self::assertNull($groups[$category->code]->unitBucket);
        }
    }

    public function testWbGroupsMirrorCatalog(): void
    {
        $groups = (new CostCategoryCatalogFacade())->wbCostCategoryGroups();

        self::assertSame(array_keys(WbCostCategory::byCode()), array_keys($groups));
        foreach (WbCostCategory::byCode() as $code => $category) {
            self::assertSame($category->widgetGroup, $groups[$code]->widgetGroup);
            self::assertSame($category->breakdownGroup, $groups[$code]->breakdownGroup);
            self::assertSame($category->unitBucket, $groups[$code]->unitBucket);
        }
    }

    public function testUnknownCodeIsAbsent(): void
    {
        $facade = new CostCategoryCatalogFacade();

        self::assertArrayNotHasKey('no_such_code', $facade->ozonCostCategoryGroups());
        self::assertArrayNotHasKey('no_such_code', $facade->wbCostCategoryGroups());
    }
}
