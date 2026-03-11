<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Entity\ProductImport;
use Doctrine\ORM\EntityManagerInterface;

final class ProductImportRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?ProductImport
    {
        return $this->entityManager->find(ProductImport::class, $id);
    }

    public function getByIdOrFail(string $id): ProductImport
    {
        $import = $this->findById($id);

        if (null === $import) {
            throw new \RuntimeException(sprintf('ProductImport "%s" not found.', $id));
        }

        return $import;
    }

    public function save(ProductImport $import): void
    {
        $this->entityManager->persist($import);
        $this->entityManager->flush();
    }
}
