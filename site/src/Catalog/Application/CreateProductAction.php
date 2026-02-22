<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\DTO\CreateProductCommand;
use App\Catalog\Domain\ProductSkuPolicy;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductStatus;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CreateProductAction
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ProductSkuPolicy $productSkuPolicy,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateProductCommand $cmd): string
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = $company->getId();

        $sku = trim((string) $cmd->sku);
        $this->productSkuPolicy->assertSkuIsUnique($sku, $companyId);

        $product = new Product(Uuid::v7()->toRfc4122(), $company);
        $product
            ->setName(trim((string) $cmd->name))
            ->setSku($sku)
            ->setStatus($cmd->status ?? ProductStatus::ACTIVE)
            ->setDescription($cmd->description)
            ->setPurchasePrice(trim((string) $cmd->purchasePrice));

        $this->entityManager->persist($product);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }

        return $product->getId();
    }
}
