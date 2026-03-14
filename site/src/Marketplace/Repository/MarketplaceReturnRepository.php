<?php

namespace App\Marketplace\Repository;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class MarketplaceReturnRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketplaceReturn::class);
    }

    public function getByCompanyQueryBuilder(Company $company): QueryBuilder
    {
        return $this->createQueryBuilder('r')
            ->where('r.company = :company')
            ->setParameter('company', $company)
            ->orderBy('r.returnDate', 'DESC');
    }

    /**
     * @return MarketplaceReturn[]
     */
    public function findByProduct(
        Product $product,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        return $this->createQueryBuilder('r')
            ->join('r.listing', 'l')
            ->where('l.product = :product')
            ->andWhere('r.returnDate >= :from')
            ->andWhere('r.returnDate <= :to')
            ->setParameter('product', $product)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('r.returnDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MarketplaceReturn[]
     */
    public function findByCompany(
        Company $company,
        \DateTimeInterface $fromDate,
        \DateTimeInterface $toDate,
    ): array {
        return $this->createQueryBuilder('r')
            ->where('r.company = :company')
            ->andWhere('r.returnDate >= :from')
            ->andWhere('r.returnDate <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $fromDate)
            ->setParameter('to', $toDate)
            ->orderBy('r.returnDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Массовая проверка существующих SRID возвратов (для bulk import)
     *
     * @param string[] $srids
     * @return array<string, true>
     */
    public function getExistingExternalIds(string $companyId, array $srids): array
    {
        if (empty($srids)) {
            return [];
        }

        $result = $this->createQueryBuilder('r')
            ->select('r.externalReturnId')
            ->where('r.company = :company')
            ->andWhere('r.externalReturnId IN (:srids)')
            ->setParameter('company', $companyId)
            ->setParameter('srids', $srids)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($result, true);
    }

    /**
     * Найти возвраты для пересчёта себестоимости.
     * Себестоимость привязана к листингу (Inventory), привязка к продукту не требуется.
     *
     * @return MarketplaceReturn[]
     */
    public function findForCostRecalculation(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        bool $onlyZeroCost,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->join('r.listing', 'l')
            ->where('r.company = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.returnDate >= :dateFrom')
            ->andWhere('r.returnDate <= :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo);

        if ($onlyZeroCost) {
            $qb->andWhere('r.costPrice IS NULL OR r.costPrice = 0');
        }

        return $qb->getQuery()->getResult();
    }
}
