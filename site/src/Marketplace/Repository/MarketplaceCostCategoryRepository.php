<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
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
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByCode(
        Company $company,
        MarketplaceType $marketplace,
        string $code
    ): ?MarketplaceCostCategory {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.marketplace = :marketplace')
            ->andWhere('c.code = :code')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
