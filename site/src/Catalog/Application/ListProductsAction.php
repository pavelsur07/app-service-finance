<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\DTO\ProductListFilter;
use App\Catalog\Infrastructure\ProductRepository;
use Pagerfanta\Pagerfanta;

final class ListProductsAction
{
    public function __construct(private readonly ProductRepository $productRepository)
    {
    }

    public function __invoke(ProductListFilter $filter, int $page, int $perPage): Pagerfanta
    {
        return $this->productRepository->paginateForCompany($filter, $page, $perPage);
    }
}
