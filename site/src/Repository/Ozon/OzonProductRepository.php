<?php

namespace App\Repository\Ozon;

use App\Entity\Company;
use App\Entity\Ozon\OzonProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OzonProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OzonProduct::class);
    }

    public function findByCompanyPaginated(Company $company, int $page = 1, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.company = :company')
            ->setParameter('company', $company)
            ->setMaxResults($limit)
            ->setFirstResult(($page - 1) * $limit)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByCompany(Company $company): int
    {
        return $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
