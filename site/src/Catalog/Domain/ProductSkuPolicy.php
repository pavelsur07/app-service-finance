<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

final class ProductSkuPolicy
{
    public function __construct(private readonly ProductSkuUniquenessChecker $productSkuUniquenessChecker)
    {
    }

    public function assertSkuIsUnique(string $sku, string $companyId): void
    {
        if ($this->productSkuUniquenessChecker->existsSkuForCompany($sku, $companyId)) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }
    }

    public function assertSkuIsUniqueExcludingProductId(string $sku, string $companyId, string $excludeProductId): void
    {
        if ($this->productSkuUniquenessChecker->existsSkuForCompanyExcludingProductId($sku, $companyId, $excludeProductId)) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }
    }
}
