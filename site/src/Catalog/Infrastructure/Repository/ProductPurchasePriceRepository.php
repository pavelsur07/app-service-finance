<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Entity\ProductPurchasePrice;
use Doctrine\ORM\EntityManagerInterface;

final class ProductPurchasePriceRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function findActiveAtDate(string $companyId, string $productId, \DateTimeImmutable $at): ?ProductPurchasePrice
    {
        return $this->entityManager->createQueryBuilder()
            ->select('pp')
            ->from(ProductPurchasePrice::class, 'pp')
            ->andWhere('pp.companyId = :companyId')
            ->andWhere('IDENTITY(pp.product) = :productId')
            ->andWhere('pp.effectiveFrom <= :at')
            ->andWhere('(pp.effectiveTo IS NULL OR pp.effectiveTo >= :at)')
            ->setParameter('companyId', $companyId)
            ->setParameter('productId', $productId)
            ->setParameter('at', $at->format('Y-m-d'))
            ->orderBy('pp.effectiveFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNextAfterDate(string $companyId, string $productId, \DateTimeImmutable $from): ?ProductPurchasePrice
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
SELECT id
FROM product_purchase_prices
WHERE company_id = :companyId
  AND product_id = :productId
  AND effective_from > :effectiveFrom
ORDER BY effective_from ASC, created_at ASC
LIMIT 1
SQL,
            [
                'companyId' => $companyId,
                'productId' => $productId,
                'effectiveFrom' => $from->format('Y-m-d'),
            ],
        );

        if (false === $id) {
            return null;
        }

        return $this->entityManager->find(ProductPurchasePrice::class, (string) $id);
    }
}
