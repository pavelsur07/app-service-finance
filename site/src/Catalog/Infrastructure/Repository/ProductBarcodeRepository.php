<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Entity\ProductBarcode;
use Doctrine\ORM\EntityManagerInterface;

final class ProductBarcodeRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Возвращает Set баркодов компании для дедубликации при импорте (O(1) lookup).
     * Загружается одним запросом перед батчем — защита от N+1.
     *
     * @return array<string, true>
     */
    public function findBarcodeSetByCompany(string $companyId): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('pb.barcode')
            ->from(ProductBarcode::class, 'pb')
            ->andWhere('pb.companyId = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getScalarResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['barcode']] = true;
        }

        return $set;
    }

    /**
     * @return ProductBarcode[]
     */
    public function findByProduct(string $productId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('pb')
            ->from(ProductBarcode::class, 'pb')
            ->andWhere('IDENTITY(pb.product) = :productId')
            ->setParameter('productId', $productId)
            ->orderBy('pb.isPrimary', 'DESC')
            ->addOrderBy('pb.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
