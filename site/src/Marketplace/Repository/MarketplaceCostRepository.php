<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceCostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceCost::class);
    }

    public function getByCompanyQueryBuilder(
        Company $company,
        ?MarketplaceType $marketplace = null,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null
    ): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.category', 'cat')->addSelect('cat')
            ->leftJoin('c.listing', 'l')->addSelect('l')
            ->where('c.company = :company')
            ->setParameter('company', $company);

        if ($marketplace !== null) {
            $qb->andWhere('c.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace);
        }

        if ($dateFrom !== null) {
            $qb->andWhere('c.costDate >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom);
        }

        if ($dateTo !== null) {
            $qb->andWhere('c.costDate <= :dateTo')
                ->setParameter('dateTo', $dateTo);
        }

        return $qb->orderBy('c.costDate', 'DESC');
    }

    /**
     * @return MarketplaceCost[]
     */
    public function findByProduct(
        Product $product,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): array {
        return $this->createQueryBuilder('c')
            ->join('c.listing', 'l')
            ->where('l.product = :product')
            ->andWhere('c.costDate >= :from')
            ->andWhere('c.costDate <= :to')
            ->setParameter('product', $product)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('c.costDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Общие затраты (не привязанные к листингу, например реклама)
     * @return MarketplaceCost[]
     */
    public function findGeneralCosts(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate
    ): array {
        return $this->createQueryBuilder('c')
            ->where('c.company = :company')
            ->andWhere('c.listing IS NULL')
            ->andWhere('c.costDate >= :from')
            ->andWhere('c.costDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('c.costDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Массовая проверка существующих external_id затрат (для bulk import)
     * Возвращает массив для isset() проверок: ['id1' => true, 'id2' => true]
     *
     * @param string $companyId
     * @param array $externalIds
     * @return array
     */
    public function getExistingExternalIds(string $companyId, array $externalIds): array
    {
        if (empty($externalIds)) {
            return [];
        }

        $result = $this->createQueryBuilder('c')
            ->select('c.externalId')
            ->where('c.company = :company')
            ->andWhere('c.externalId IN (:ids)')
            ->setParameter('company', $companyId)
            ->setParameter('ids', $externalIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($result, true);
    }

    public function findExportRowsByCompanyAndFilters(
        Company $company,
        ?MarketplaceType $marketplace,
        ?\DateTimeImmutable $dateFrom,
        ?\DateTimeImmutable $dateTo,
        ?string $categoryId,
        string $mapped,
    ): array {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $qb
            ->select(
                'c.id',
                'c.cost_date',
                'c.marketplace',
                'c.amount',
                'c.operation_type',
                'c.description',
                'c.external_id',
                'c.raw_document_id',
                'cc.id AS category_id',
                'cc.code AS category_code',
                'cc.name AS category_name',
                'l.id AS listing_id',
                'l.marketplace_sku',
                'l.supplier_sku',
                'l.size AS listing_size',
                'l.name AS listing_name',
            )
            ->from('marketplace_costs', 'c')
            ->leftJoin('c', 'marketplace_cost_categories', 'cc', 'cc.id = c.category_id')
            ->leftJoin('c', 'marketplace_listings', 'l', 'l.id = c.listing_id')
            ->where('c.company_id = :companyId')
            ->setParameter('companyId', (string) $company->getId())
            ->orderBy('c.cost_date', 'DESC');

        if ($marketplace !== null) {
            $qb->andWhere('c.marketplace = :marketplace')
                ->setParameter('marketplace', $marketplace->value);
        }

        if ($dateFrom !== null) {
            $qb->andWhere('c.cost_date >= :dateFrom')
                ->setParameter('dateFrom', $dateFrom->format('Y-m-d'));
        }

        if ($dateTo !== null) {
            $qb->andWhere('c.cost_date <= :dateTo')
                ->setParameter('dateTo', $dateTo->format('Y-m-d'));
        }

        if ($categoryId !== null) {
            $qb->andWhere('c.category_id = :categoryId')
                ->setParameter('categoryId', $categoryId);
        }

        if ($mapped === 'linked') {
            $qb->andWhere('c.listing_id IS NOT NULL');
        } elseif ($mapped === 'general') {
            $qb->andWhere('c.listing_id IS NULL');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
