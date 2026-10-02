<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

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
        Company $company,
        MarketplaceType $marketplace,
        string $externalOrderId,
        string $marketplaceSku,
    ): ?MarketplaceSale {
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
     * Массовая загрузка продаж по списку externalOrderId (srid) для bulk-обработки.
     *
     * @param string[] $externalOrderIds
     *
     * @return array<string, MarketplaceSale> ключ — externalOrderId
     */
    public function findByMarketplaceOrdersIndexed(
        Company $company,
        MarketplaceType $marketplace,
        array $externalOrderIds,
    ): array {
        if (empty($externalOrderIds)) {
            return [];
        }

        $sales = $this->createQueryBuilder('s')
            ->where('s.company = :company')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.externalOrderId IN (:orderIds)')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('orderIds', $externalOrderIds)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($sales as $sale) {
            $map[$sale->getExternalOrderId()] = $sale;
        }

        return $map;
    }

    /**
     * Массовая проверка существующих SRID (для bulk import).
     *
     * @param string[] $srids
     *
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
     * Метки записей по external id: начисление-источник (`raw_data._accrual_id`, у исторических записей его нет) и дата.
     * Нужны Ozon by-day, чтобы отличить повторную обработку того же начисления от другого начисления того же отправления.
     *
     * @param list<string> $externalIds
     *
     * @return array<string, array{accrualId: ?string, date: string}>
     */
    public function getAccrualStamps(string $companyId, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT external_order_id AS external_id, sale_date AS stamp_date, raw_data ->> \'_accrual_id\' AS accrual_id
             FROM marketplace_sales
             WHERE company_id = :companyId AND external_order_id IN (:ids)',
            ['companyId' => $companyId, 'ids' => $externalIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $stamps = [];
        foreach ($rows as $row) {
            $stamps[(string) $row['external_id']] = [
                'accrualId' => null === $row['accrual_id'] ? null : (string) $row['accrual_id'],
                'date' => (string) $row['stamp_date'],
            ];
        }

        return $stamps;
    }

    /**
     * Найти продажи для пересчёта себестоимости.
     * Себестоимость привязана к листингу (Inventory), привязка к продукту не требуется.
     *
     * @return MarketplaceSale[]
     */
    public function findForCostRecalculation(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        bool $onlyZeroCost,
        array $listingIds = [],
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->join('s.listing', 'l')
            ->addSelect('l')
            ->where('s.company = :companyId')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.saleDate >= :dateFrom')
            ->andWhere('s.saleDate <= :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo);

        if ([] !== $listingIds) {
            $qb->andWhere('l.id IN (:listingIds)')
                ->setParameter('listingIds', array_values(array_unique($listingIds)));
        }

        if ($onlyZeroCost) {
            $qb->andWhere('s.costPrice IS NULL OR s.costPrice = 0');
        }

        return $qb->getQuery()->getResult();
    }

    public function countDocumentLinkedByRawDocument(
        Company $company,
        MarketplaceType $marketplace,
        string $rawDocumentId,
    ): int {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.company = :company')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.rawDocumentId = :rawDocumentId')
            ->andWhere('s.document IS NOT NULL')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteByRawDocument(
        Company $company,
        MarketplaceType $marketplace,
        string $rawDocumentId,
    ): int {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.company = :company')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.rawDocumentId = :rawDocumentId')
            ->andWhere('s.document IS NULL')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->getQuery()
            ->execute();
    }

    /**
     * @param string[] $externalOrderIds
     */
    public function deleteOpenByExternalIds(
        Company $company,
        MarketplaceType $marketplace,
        array $externalOrderIds,
    ): int {
        if (empty($externalOrderIds)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.company = :company')
            ->andWhere('s.marketplace = :marketplace')
            ->andWhere('s.externalOrderId IN (:externalOrderIds)')
            ->andWhere('s.document IS NULL')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('externalOrderIds', array_values(array_unique($externalOrderIds)))
            ->getQuery()
            ->execute();
    }
}
