<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\ProductSkuUniquenessChecker;

final class ProductSkuUniquenessCheckerDoctrine implements ProductSkuUniquenessChecker
{
    public function __construct(private readonly ProductRepository $productRepository)
    {
    }

    public function existsSkuForCompany(string $sku, string $companyId): bool
    {
        return $this->productRepository->existsSkuForCompany($sku, $companyId);
    }

    public function existsSkuForCompanyExcludingProductId(string $sku, string $companyId, string $excludeProductId): bool
    {
        return $this->productRepository->existsSkuForCompanyExcludingProductId($sku, $companyId, $excludeProductId);
    }
}

