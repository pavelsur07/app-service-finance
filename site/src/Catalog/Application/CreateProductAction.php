<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\DTO\CreateProductCommand;
use App\Catalog\Domain\InternalArticleGenerator;
use App\Catalog\Domain\ProductSkuPolicy;
use App\Catalog\Entity\Product;
use App\Catalog\Enum\ProductStatus;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CreateProductAction
{
    public function __construct(
        private readonly ProductSkuPolicy $productSkuPolicy,
        private readonly InternalArticleGenerator $articleGenerator,
        private readonly CompanyFacade $companyFacade,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId, CreateProductCommand $cmd): string
    {
        $company = $this->companyFacade->findById($companyId);
        if (null === $company) {
            throw new \DomainException(sprintf('Компания "%s" не найдена.', $companyId));
        }

        $sku = trim((string) $cmd->sku);
        $this->productSkuPolicy->assertSkuIsUnique($sku, $companyId);

        $product = new Product(Uuid::uuid7()->toString(), $company);
        $product
            ->setName(trim((string) $cmd->name))
            ->setSku($sku)
            ->setVendorSku($cmd->vendorSku ?? null)
            ->setStatus($cmd->status ?? ProductStatus::ACTIVE)
            ->setDescription($cmd->description ?? null);

        $product->assignInternalArticle($this->articleGenerator->generate($companyId));

        $this->entityManager->persist($product);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new \DomainException('Товар с таким SKU уже существует в активной компании.');
        }

        return $product->getId();
    }
}
