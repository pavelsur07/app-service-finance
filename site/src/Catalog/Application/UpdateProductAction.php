<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\DTO\UpdateProductCommand;
use App\Catalog\Infrastructure\ProductRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateProductAction
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $id, UpdateProductCommand $cmd): void
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $product = $this->productRepository->getOneForCompanyOrNull($id, $company);

        if (null === $product) {
            throw new NotFoundHttpException();
        }

        $name = trim((string) $cmd->name);
        if ($name !== $product->getName()) {
            $product->setName($name);
        }

        $sku = trim((string) $cmd->sku);
        if ($sku !== $product->getSku()) {
            if ($this->productRepository->existsSkuForCompanyExcludingProductId($sku, $company, $product->getId())) {
                throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
            }

            $product->setSku($sku);
        }

        if (null !== $cmd->status && $cmd->status !== $product->getStatus()) {
            $product->setStatus($cmd->status);
        }

        if ($cmd->description !== $product->getDescription()) {
            $product->setDescription($cmd->description);
        }

        $purchasePrice = trim((string) $cmd->purchasePrice);
        if ($purchasePrice !== $product->getPurchasePrice()) {
            $product->setPurchasePrice($purchasePrice);
        }

        // TODO: If product-specific audit integration is introduced, call it from here.
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }
    }
}
