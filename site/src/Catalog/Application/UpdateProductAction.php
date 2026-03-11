<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\DTO\UpdateProductCommand;
use App\Catalog\Domain\ProductSkuPolicy;
use App\Catalog\Infrastructure\ProductRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UpdateProductAction
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly ProductSkuPolicy $productSkuPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId, string $id, UpdateProductCommand $cmd): void
    {
        $product = $this->productRepository->findByIdAndCompany($id, $companyId);

        if (null === $product) {
            throw new NotFoundHttpException();
        }

        $name = trim((string) $cmd->name);
        if ($name !== $product->getName()) {
            $product->setName($name);
        }

        $sku = trim((string) $cmd->sku);
        if ($sku !== $product->getSku()) {
            $this->productSkuPolicy->assertSkuIsUniqueExcludingProductId($sku, $companyId, $product->getId());
            $product->setSku($sku);
        }

        if (null !== $cmd->status && $cmd->status !== $product->getStatus()) {
            $product->setStatus($cmd->status);
        }

        if ($cmd->description !== $product->getDescription()) {
            $product->setDescription($cmd->description);
        }

        // Цена управляется отдельно через SetPurchasePriceAction
        // purchasePrice намеренно отсутствует здесь

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }
    }
}
