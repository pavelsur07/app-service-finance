<?php

namespace App\Marketplace\Repository;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceReturn;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceReturn::class);
    }

    public function getByCompanyQueryBuilder(Company $company): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->where('r.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.returnDate', 'DESC');
    }

    /**
     * @return MarketplaceReturn[]
     */
    public function findByProduct(
        Product $product,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        return $this->createQueryBuilder('r')
            ->where('r.product = :product')
            ->andWhere('r.returnDate >= :from')
            ->andWhere('r.returnDate <= :to')
            ->setParameter('product', $product)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('r.returnDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MarketplaceReturn[]
     */
    public function findByCompany(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        return $this->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.returnDate >= :from')
            ->andWhere('r.returnDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('r.returnDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
