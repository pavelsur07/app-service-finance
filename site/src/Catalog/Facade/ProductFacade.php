<?php

declare(strict_types=1);

namespace App\Catalog\Facade;

use App\Catalog\Entity\Product;
use App\Catalog\Infrastructure\ProductRepository;

final readonly class ProductFacade
{
    public function __construct(private ProductRepository $productRepository)
    {
    }

    public function findByIdAndCompany(string $productId, string $companyId): ?Product
    {
        return $this->productRepository->findByIdAndCompany($productId, $companyId);
    }
}

