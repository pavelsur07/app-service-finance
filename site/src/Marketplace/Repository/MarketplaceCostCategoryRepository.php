<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceCostCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceCostCategory::class);
    }

    /**
     * @return MarketplaceCostCategory[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.isActive = :active')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCode(Company $company, string $code): ?MarketplaceCostCategory
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.code = :code')
            ->setParameter('company', $company)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
