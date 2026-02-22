<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\ProductSkuPolicy;
use App\Catalog\Domain\ProductSkuUniquenessChecker;
use PHPUnit\Framework\TestCase;

final class ProductSkuPolicyTest extends TestCase
{
    public function testAssertSkuIsUniqueThrowsWhenSkuAlreadyExists(): void
    {
        $policy = new ProductSkuPolicy(new class implements ProductSkuUniquenessChecker {
            public function existsSkuForCompany(string $sku, string $companyId): bool
            {
                return true;
            }

            public function existsSkuForCompanyExcludingProductId(string $sku, string $companyId, string $excludeProductId): bool
            {
                return false;
            }
        });

        $this->expectException(\DomainException::class);

        $policy->assertSkuIsUnique('SKU-1', 'company-1');
    }

    public function testAssertSkuIsUniqueExcludingProductIdThrowsWhenSkuAlreadyExists(): void
    {
        $policy = new ProductSkuPolicy(new class implements ProductSkuUniquenessChecker {
            public function existsSkuForCompany(string $sku, string $companyId): bool
            {
                return false;
            }

            public function existsSkuForCompanyExcludingProductId(string $sku, string $companyId, string $excludeProductId): bool
            {
                return true;
            }
        });

        $this->expectException(\DomainException::class);

        $policy->assertSkuIsUniqueExcludingProductId('SKU-1', 'company-1', 'product-1');
    }
}
