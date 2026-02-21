<?php

namespace App\Marketplace\Repository;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceSaleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceSale::class);
    }

    public function getByCompanyQueryBuilder(Company $company): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->where('s.company = :company')
            ->setParameter('company', $company)
            ->orderBy('s.saleDate', 'DESC');
    }

    public function findByMarketplaceOrder(
        Company $company,
        MarketplaceType $marketplace,
        string $externalOrderId
    ): ?MarketplaceSale {
        return $this->createQueryBuilder('s')
            ->where('s.company = :company')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.externalOrderId = :orderId')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('orderId', $externalOrderId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return MarketplaceSale[]
     */
    public function findByProduct(
        Product $product,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): array {
        return $this->createQueryBuilder('s')
            ->where('s.product = :product')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('product', $product)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('s.saleDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Product[]
     */
    public function findProductsWithSales(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): array {
        $qb = $this->createQueryBuilder('s');

        return $qb
            ->select('DISTINCT p')
            ->join('s.product', 'p')
            ->where('s.company = :company')
            ->andWhere('s.saleDate >= :from')
            ->andWhere('s.saleDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->getQuery()
            ->getResult();
    }

    /**
     * Массовая проверка существующих SRID (для bulk import)
     * Возвращает массив для isset() проверок: ['srid1' => true, 'srid2' => true]
     *
     * @param string $companyId
     * @param array $srids
     * @return array
     */
    public function getExistingExternalIds(string $companyId, array $srids): array
    {
        if (empty($srids)) {
            return [];
        }

        $result = $this->createQueryBuilder('s')
            ->select('s.externalOrderId')
            ->where('s.company = :company')
            ->andWhere('s.externalOrderId IN (:srids)')
            ->setParameter('company', $companyId)
            ->setParameter('srids', $srids)
            ->getQuery()
            ->getSingleColumnResult();

        // Возвращаем как map для быстрого isset()
        return array_fill_keys($result, true);
    }
}
