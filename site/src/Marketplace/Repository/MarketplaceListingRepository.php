<?php

namespace App\Marketplace\Repository;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceListing::class);
    }

    public function findByMarketplaceSku(
        Company $company,
        MarketplaceType $marketplace,
        string $marketplaceSku
    ): ?MarketplaceListing {
        return $this->createQueryBuilder('l')
            ->where('l.company = :company')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('l.marketplaceSku = :sku')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('sku', $marketplaceSku)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return MarketplaceListing[]
     */
    public function findByProduct(Product $product): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.product = :product')
            ->andWhere('l.isActive = :active')
            ->setParameter('product', $product)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MarketplaceListing[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.company = :company')
            ->andWhere('l.isActive = :active')
            ->setParameter('company', $company)
            ->setParameter('active', true)
            ->orderBy('l.product', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
