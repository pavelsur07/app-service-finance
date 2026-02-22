<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Infrastructure\ProductRepository;
use App\Company\Entity\Company;

final class ProductSkuPolicy
{
    public function __construct(private readonly ProductRepository $productRepository)
    {
    }

    public function assertSkuIsUnique(string $sku, Company $company): void
    {
        if ($this->productRepository->existsSkuForCompany($sku, $company)) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }
    }
}
