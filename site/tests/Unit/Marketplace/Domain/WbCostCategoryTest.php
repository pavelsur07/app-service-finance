<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Domain;

use App\Marketplace\Domain\WbCostCategory;
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
        $this->assertArrayHasKey('product_processing', $byCode);
    }

    public function testUnitBucketBelongsToKnownSet(): void
    {
        $allowed = ['commission', 'logistics', 'other'];

        foreach (WbCostCategory::all() as $category) {
            $this->assertContains($category->unitBucket, $allowed);
        }
    }
}
