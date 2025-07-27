<?php

namespace App\Repository\Ozon;

use App\Entity\Company;
use App\Entity\Ozon\OzonProduct;
use App\Entity\Ozon\OzonProductSales;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OzonProductSalesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonProductSales::class);
    }

    public function findOneByPeriod(OzonProduct $product, Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): ?OzonProductSales
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.product = :product')
            ->andWhere('s.company = :company')
            ->andWhere('s.dateFrom = :from')
            ->andWhere('s.dateTo = :to')
            ->setParameter('product', $product)
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
