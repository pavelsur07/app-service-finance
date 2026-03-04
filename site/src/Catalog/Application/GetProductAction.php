<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Entity\Product;
use App\Catalog\Infrastructure\ProductRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetProductAction
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {
    }

    public function __invoke(string $companyId, string $id): Product
    {
        $product = $this->productRepository->findByIdAndCompany($id, $companyId);

        if (null === $product) {
            throw new NotFoundHttpException();
        }

        return $product;
    }
}
