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


    /**
     * Найти продажу по posting_number + SKU листинга.
     * Используется при обработке realization-документа Ozon
     * для обновления totalRevenue из sale.amount.
     *
     * В realization одна строка = один SKU в одном отправлении.
     * SKU хранится в marketplace_listings.marketplace_sku.
     */
    public function findByMarketplaceOrderAndSku(
        Company         $company,
        MarketplaceType $marketplace,
        string          $externalOrderId,
        string          $marketplaceSku,
    ): ?MarketplaceSale
    {
        return $this->createQueryBuilder('s')
            ->join('s.listing', 'l')
            ->where('s.company = :company')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.externalOrderId = :orderId')
            ->andWhere('l.marketplaceSku = :sku')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('orderId', $externalOrderId)
            ->setParameter('sku', $marketplaceSku)
            ->getQuery()
            ->getOneOrNullResult();
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
        string $externalOrderId,
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
        \DateTimeInterface $toDate,
    ): array {
        return $this->createQueryBuilder('s')
            ->join('s.listing', 'l')
            ->where('l.product = :product')
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
        \DateTimeInterface $toDate,
    ): array {
        return $this->createQueryBuilder('s')
            ->select('DISTINCT p')
            ->join('s.listing', 'l')
            ->join('l.product', 'p')
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
     *
     * @param string[] $srids
     * @return array<string, true>
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

        return array_fill_keys($result, true);
    }

    /**
     * Найти продажи для пересчёта себестоимости.
     * Только продажи с привязанным продуктом (listing.product IS NOT NULL).
     *
     * @return MarketplaceSale[]
     */
    public function findForCostRecalculation(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        bool $onlyZeroCost,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->join('s.listing', 'l')
            ->where('s.company = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.saleDate >= :dateFrom')
            ->andWhere('s.saleDate <= :dateTo')
            ->andWhere('l.product IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo);

        if ($onlyZeroCost) {
            $qb->andWhere('s.costPrice IS NULL OR s.costPrice = 0');
        }

        return $qb->getQuery()->getResult();
    }
}
