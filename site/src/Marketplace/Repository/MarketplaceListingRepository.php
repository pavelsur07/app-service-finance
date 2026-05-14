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


    // -------------------------------------------------------------------------
    // Добавить в MarketplaceListingRepository
    // Worker-safe: принимает companyId string, не объект Company
    // -------------------------------------------------------------------------

    /**
     * Найти все листинги компании для указанного маркетплейса.
     * Worker-safe: принимает companyId как string, не объект Company.
     *
     * @return MarketplaceListing[]
     */
    public function findByCompanyIdAndMarketplace(
        string          $companyId,
        MarketplaceType $marketplace,
    ): array
    {
        return $this->createQueryBuilder('l')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('l.marketplace = :marketplace')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->getQuery()
            ->getResult();
    }

    /** @return MarketplaceListing[] */
    public function findAllByCompanyMarketplaceAndMarketplaceSku(
        string $companyId,
        MarketplaceType $marketplace,
        string $sku,
    ): array {
        return $this->createQueryBuilder('l')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('l.marketplaceSku = :sku')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('sku', $sku)
            ->getQuery()
            ->getResult();
    }

    /** @return MarketplaceListing[] */
    public function findAllByCompanyMarketplaceAndSupplierSku(
        string $companyId,
        MarketplaceType $marketplace,
        string $supplierSku,
    ): array {
        return $this->createQueryBuilder('l')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('l.supplierSku = :supplierSku')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('supplierSku', $supplierSku)
            ->getQuery()
            ->getResult();
    }

    public function save(MarketplaceListing $listing): void
    {
        $this->getEntityManager()->persist($listing);
        $this->getEntityManager()->flush();
    }

    public function findByIdAndCompany(string $listingId, string $companyId): ?MarketplaceListing
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')
            ->andWhere('IDENTITY(l.company) = :companyId')
            ->setParameter('id', $listingId)
            ->setParameter('companyId', $companyId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Проверить: привязан ли уже этот товар к листингу данного маркетплейса в компании.
     * Используется перед маппингом для соблюдения правила: один товар — один листинг на маркетплейс.
     * Исключает текущий листинг чтобы разрешить повторную привязку к тому же товару.
     */
    public function findByProductAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        string $productId,
        ?string $excludeListingId = null,
    ): ?MarketplaceListing {
        $qb = $this->createQueryBuilder('l')
            ->andWhere('IDENTITY(l.company) = :companyId')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('IDENTITY(l.product) = :productId')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('productId', $productId)
            ->setMaxResults(1);

        if ($excludeListingId !== null) {
            $qb->andWhere('l.id != :excludeId')
                ->setParameter('excludeId', $excludeListingId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findByMarketplaceSku(
        Company $company,
        MarketplaceType $marketplace,
        string $marketplaceSku,
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

    public function findByNmIdAndSize(
        Company $company,
        MarketplaceType $marketplace,
        string $nmId,
        string $size,
    ): ?MarketplaceListing {
        return $this->createQueryBuilder('l')
            ->where('l.company = :company')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('l.marketplaceSku = :nmId')
            ->andWhere('l.size = :size')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('nmId', $nmId)
            ->setParameter('size', $size)
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
     * @param string[] $productIds
     * @return array<string, string[]>
     */
    public function findMarketplaceNamesByProductIds(string $companyId, array $productIds): array
    {
        if ([] === $productIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.product) AS productId, l.marketplace AS marketplace')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('IDENTITY(l.product) IN (:productIds)')
            ->andWhere('l.isActive = :active')
            ->setParameter('companyId', $companyId)
            ->setParameter('productIds', $productIds)
            ->setParameter('active', true)
            ->getQuery()
            ->getArrayResult();

        $marketplacesByProductId = [];
        foreach ($rows as $row) {
            $productId = (string) $row['productId'];
            $marketplace = $row['marketplace'] instanceof MarketplaceType
                ? $row['marketplace']->value
                : (string) $row['marketplace'];

            if (!isset($marketplacesByProductId[$productId])) {
                $marketplacesByProductId[$productId] = [];
            }

            if (!in_array($marketplace, $marketplacesByProductId[$productId], true)) {
                $marketplacesByProductId[$productId][] = $marketplace;
            }
        }

        return $marketplacesByProductId;
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

    /**
     * @param string[] $skus
     * @return array<string, MarketplaceListing>
     */
    public function findListingsBySkusIndexed(
        Company $company,
        MarketplaceType $marketplace,
        array $skus,
    ): array {
        if (empty($skus)) {
            return [];
        }

        $listings = $this->createQueryBuilder('l')
            ->leftJoin('l.product', 'p')
            ->addSelect('p')
            ->where('l.company = :company')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('l.marketplaceSku IN (:skus)')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('skus', $skus)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($listings as $listing) {
            $indexed[$listing->getMarketplaceSku()] = $listing;
        }

        return $indexed;
    }

    /**
     * Возвращает map listingId → productId|null для листингов указанной компании.
     *
     * Не возвращает листинги, не принадлежащие компании (IDOR-защита через WHERE company_id).
     * Листинги, не существующие в БД или принадлежащие другой компании, отсутствуют в результирующем массиве.
     * productId = null если листинг существует, но не привязан к продукту (orphan).
     *
     * @param string         $companyId
     * @param array<string>  $listingIds
     * @return array<string, string|null> map listingId => productId|null
     */
    public function findListingToProductMap(string $companyId, array $listingIds): array
    {
        if ([] === $listingIds) {
            return [];
        }

        $uniqueIds = array_values(array_unique($listingIds));

        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS listingId', 'IDENTITY(l.product) AS productId')
            ->where('IDENTITY(l.company) = :companyId')
            ->andWhere('l.id IN (:listingIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('listingIds', $uniqueIds)
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $listingId = (string) $row['listingId'];
            $productId = $row['productId'] !== null ? (string) $row['productId'] : null;
            $map[$listingId] = $productId;
        }

        return $map;
    }

    /**
     * @param string[] $nmIds
     * @return array<string, MarketplaceListing> ['nmId_size' => Listing]
     */
    public function findListingsByNmIdsIndexed(
        Company $company,
        MarketplaceType $marketplace,
        array $nmIds,
    ): array {
        if (empty($nmIds)) {
            return [];
        }

        $listings = $this->createQueryBuilder('l')
            ->leftJoin('l.product', 'p')
            ->addSelect('p')
            ->where('l.company = :company')
            ->andWhere('l.marketplace = :marketplace')
            ->andWhere('l.marketplaceSku IN (:nmIds)')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('nmIds', $nmIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($listings as $listing) {
            $key = $listing->getMarketplaceSku() . '_' . $listing->getSize();
            $indexed[$key] = $listing;
        }

        return $indexed;
    }
}
