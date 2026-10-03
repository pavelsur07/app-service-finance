<?php

declare(strict_types=1);

namespace App\Marketplace\Repository;

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
     * Массовая проверка существующих SRID возвратов (для bulk import).
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
     * Метки записей по external id: начисление-источник (`raw_data._accrual_id`, у исторических записей его нет), дата и сумма.
     * Нужны Ozon by-day, чтобы отличить повторную обработку того же начисления от другого начисления того же отправления.
     *
     * @param list<string> $externalIds
     *
     * @return array<string, array{accrualId: ?string, date: string, amount: string}>
     */
    public function getAccrualStamps(string $companyId, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT external_return_id AS external_id, return_date AS stamp_date, refund_amount AS stamp_amount, raw_data ->> \'_accrual_id\' AS accrual_id
             FROM marketplace_returns
             WHERE company_id = :companyId AND external_return_id IN (:ids)',
            ['companyId' => $companyId, 'ids' => $externalIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $stamps = [];
        foreach ($rows as $row) {
            $stamps[(string) $row['external_id']] = [
                'accrualId' => null === $row['accrual_id'] ? null : (string) $row['accrual_id'],
                'date' => (string) $row['stamp_date'],
                'amount' => number_format((float) $row['stamp_amount'], 2, '.', ''),
            ];
        }

        return $stamps;
    }

    /**
     * Проставляет метку начисления исторической записи, которая ему принадлежит (в `raw_data` добавляется `_accrual_id`).
     * Суммы, даты и привязки к ОПиУ не меняются; запись с уже стоящей меткой не трогается. Возвращает число изменённых строк.
     */
    public function claimLegacyRecord(string $companyId, string $externalId, string $accrualId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE marketplace_returns
             SET raw_data = (COALESCE(raw_data::jsonb, \'{}\'::jsonb) || jsonb_build_object(\'_accrual_id\', CAST(:accrualId AS text)))::json
             WHERE company_id = :companyId AND external_return_id = :externalId AND raw_data ->> \'_accrual_id\' IS NULL',
            ['companyId' => $companyId, 'externalId' => $externalId, 'accrualId' => $accrualId],
        );
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
        array $listingIds = [],
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->join('r.listing', 'l')
            ->addSelect('l')
            ->leftJoin('r.sale', 's')
            ->addSelect('s')
            ->where('r.company = :companyId')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.returnDate >= :dateFrom')
            ->andWhere('r.returnDate <= :dateTo')
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('dateFrom', $dateFrom)
            ->setParameter('dateTo', $dateTo);

        if ([] !== $listingIds) {
            $qb->andWhere('l.id IN (:listingIds)')
                ->setParameter('listingIds', array_values(array_unique($listingIds)));
        }

        if ($onlyZeroCost) {
            $qb->andWhere('r.costPrice IS NULL OR r.costPrice = 0');
        }

        return $qb->getQuery()->getResult();
    }

    public function countDocumentLinkedByRawDocument(
        Company $company,
        MarketplaceType $marketplace,
        string $rawDocumentId,
    ): int {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.company = :company')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.rawDocumentId = :rawDocumentId')
            ->andWhere('r.document IS NOT NULL')
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
        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.company = :company')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.rawDocumentId = :rawDocumentId')
            ->andWhere('r.document IS NULL')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('rawDocumentId', $rawDocumentId)
            ->getQuery()
            ->execute();
    }

    /**
     * @param string[] $externalReturnIds
     */
    public function deleteOpenByExternalIds(
        Company $company,
        MarketplaceType $marketplace,
        array $externalReturnIds,
    ): int {
        if (empty($externalReturnIds)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->where('r.company = :company')
            ->andWhere('r.marketplace = :marketplace')
            ->andWhere('r.externalReturnId IN (:externalReturnIds)')
            ->andWhere('r.document IS NULL')
            ->setParameter('company', $company)
            ->setParameter('marketplace', $marketplace)
            ->setParameter('externalReturnIds', array_values(array_unique($externalReturnIds)))
            ->getQuery()
            ->execute();
    }
}
