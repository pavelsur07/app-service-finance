<?php

namespace App\Repository\Ozon;

use App\Entity\Company;
use App\Entity\Ozon\OzonProduct;
use App\Entity\Ozon\OzonProductStock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OzonProductStockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonProductStock::class);
    }

    public function findOneForDate(OzonProduct $product, Company $company, \DateTimeImmutable $date): ?OzonProductStock
    {
        $start = $date->setTime(0, 0);
        $end = $start->modify('+1 day');

        return $this->createQueryBuilder('s')
            ->andWhere('s.product = :product')
            ->andWhere('s.company = :company')
            ->andWhere('s.updatedAt >= :start')
            ->andWhere('s.updatedAt < :end')
            ->setParameter('product', $product)
            ->setParameter('company', $company)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
