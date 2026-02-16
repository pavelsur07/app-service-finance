<?php

namespace App\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceConnection::class);
    }

    /**
     * @return MarketplaceConnection[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->setParameter('company', $company)
            ->orderBy('c.marketplace', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByMarketplace(
        Company $company,
        MarketplaceType $marketplace,
    ): ?MarketplaceConnection {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.marketplace = :marketplace')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return MarketplaceConnection[]
     */
    public function findActiveConnections(Company $company): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.isActive = :active')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
