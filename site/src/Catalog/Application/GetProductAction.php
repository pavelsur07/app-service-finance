<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Entity\Product;
use App\Catalog\Infrastructure\ProductRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GetProductAction
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    public function __invoke(string $id): Product
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $product = $this->productRepository->getOneForCompanyOrNull($id, $company);

        if (null === $product) {
            throw new NotFoundHttpException();
        }

        return $product;
    }
}

