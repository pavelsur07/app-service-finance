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
            ->andWhere('IDENTITY(pp.company) = :companyId')
            ->andWhere('IDENTITY(pp.product) = :productId')
            ->andWhere('pp.effectiveFrom <= :at')
            ->andWhere('(pp.effectiveTo IS NULL OR pp.effectiveTo >= :at)')
            ->setParameter('companyId', $companyId)
            ->setParameter('productId', $productId)
            ->setParameter('at', $at)
            ->orderBy('pp.effectiveFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNextAfterDate(string $companyId, string $productId, \DateTimeImmutable $from): ?ProductPurchasePrice
    {
        return $this->entityManager->createQueryBuilder()
            ->select('pp')
            ->from(ProductPurchasePrice::class, 'pp')
            ->andWhere('IDENTITY(pp.company) = :companyId')
            ->andWhere('IDENTITY(pp.product) = :productId')
            ->andWhere('pp.effectiveFrom > :from')
            ->setParameter('companyId', $companyId)
            ->setParameter('productId', $productId)
            ->setParameter('from', $from)
            ->orderBy('pp.effectiveFrom', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

