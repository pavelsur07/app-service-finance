<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

interface ProductSkuUniquenessChecker
{
    public function existsSkuForCompany(string $sku, string $companyId): bool;

    public function existsSkuForCompanyExcludingProductId(string $sku, string $companyId, string $excludeProductId): bool;
}

