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

    /**
     * Сохранить листинг
     */
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

    public function findByNmIdAndSize(
        Company $company,
        MarketplaceType $marketplace,
        string $nmId,
        string $size  // Теперь ВСЕГДА строка ('UNKNOWN' если размера нет)
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
     *
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
     * Массовая загрузка листингов по nm_id с индексацией по ключу "nmId_size"
     * Для bulk import - одним запросом вместо тысяч
     *
     * @param Company $company
     * @param MarketplaceType $marketplace
     * @param array $nmIds
     * @return array Индексированный массив: ['nmId_size' => Listing, 'nmId_UNKNOWN' => Listing]
     */
    public function findListingsByNmIdsIndexed(
        Company $company,
        MarketplaceType $marketplace,
        array $nmIds
    ): array {
        if (empty($nmIds)) {
            return [];
        }

        // Загружаем все листинги + продукты одним запросом
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

        // Индексируем по ключу: nmId_size (size всегда строка, 'UNKNOWN' если размера нет)
        $indexed = [];
        foreach ($listings as $listing) {
            $key = $listing->getMarketplaceSku() . '_' . $listing->getSize();
            $indexed[$key] = $listing;
        }

        return $indexed;
    }
}
