<?php

namespace App\Marketplace\Repository;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceCostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceCost::class);
    }

    /**
     * @return MarketplaceCost[]
     */
    public function findByProduct(
        Product $product,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): array {
        return $this->createQueryBuilder('c')
            ->where('c.product = :product')
            ->andWhere('c.costDate >= :from')
            ->andWhere('c.costDate <= :to')
            ->setParameter('product', $product)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('c.costDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Общие затраты (не привязанные к товару, например реклама)
     * @return MarketplaceCost[]
     */
    public function findGeneralCosts(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): array {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.product IS NULL')
            ->andWhere('c.costDate >= :from')
            ->andWhere('c.costDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('c.costDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
