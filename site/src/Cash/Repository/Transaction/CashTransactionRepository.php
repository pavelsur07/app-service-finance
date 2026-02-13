<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

class CashTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransaction::class);
    }

    /**
     * @return list<array{date:string,inflow:string,outflow:string}>
     */
    public function sumByDay(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.occurredAt as date',
                "SUM(CASE WHEN t.direction = 'INFLOW' THEN t.amount ELSE 0 END) as inflow",
                "SUM(CASE WHEN t.direction = 'OUTFLOW' THEN t.amount ELSE 0 END) as outflow"
            )
            ->where('t.company = :company')
            ->andWhere('t.moneyAccount = :account')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->groupBy('date')
            ->orderBy('date', 'ASC');

        return $qb->getQuery()->getArrayResult();
    }

    public function existsByCompanyAndDedupe(string $companyId, string $dedupeHash): bool
    {
        return (bool) $this->createQueryBuilder('t')
            ->select('1')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.dedupeHash = :dedupeHash')
            ->andWhere('t.deletedAt IS NULL')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('dedupeHash', $dedupeHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByImport(string $companyId, string $source, string $externalId): ?CashTransaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.importSource = :source')
            ->andWhere('t.externalId = :externalId')
            ->andWhere('t.deletedAt IS NULL')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('source', $source)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function paginateDeletedByCompany(string $companyId, int $page, int $perPage): Pagerfanta
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.deletedAt IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('t.deletedAt', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }

    public function paginateByCompanyWithFilters(
        Company $company,
        array $filters,
        int $page,
        int $perPage,
    ): Pagerfanta {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'DESC');

        if ($filters['dateFrom']) {
            $qb->andWhere('t.occurredAt >= :df')->setParameter('df', new \DateTimeImmutable($filters['dateFrom']));
        }
        if ($filters['dateTo']) {
            $qb->andWhere('t.occurredAt <= :dt')->setParameter('dt', new \DateTimeImmutable($filters['dateTo']));
        }
        if ($filters['accountId']) {
            $qb->andWhere('t.moneyAccount = :acc')->setParameter('acc', $filters['accountId']);
        }
        if ($filters['categoryId']) {
            $qb->andWhere('t.cashflowCategory = :cat')->setParameter('cat', $filters['categoryId']);
        }
        if ($filters['counterpartyId']) {
            $qb->andWhere('t.counterparty = :cp')->setParameter('cp', $filters['counterpartyId']);
        }
        if ($filters['direction']) {
            $qb->andWhere('t.direction = :dir')->setParameter('dir', $filters['direction']);
        }
        if ($filters['amountMin']) {
            $qb->andWhere('t.amount >= :amin')->setParameter('amin', $filters['amountMin']);
        }
        if ($filters['amountMax']) {
            $qb->andWhere('t.amount <= :amax')->setParameter('amax', $filters['amountMax']);
        }
        if ($filters['q']) {
            $qb->andWhere('t.description LIKE :q')->setParameter('q', '%'.$filters['q'].'%');
        }

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }

    /**
     * @param list<string> $accountIds
     *
     * @return array<string, array{inflow: string, outflow: string}>
     */
    public function sumByAccountAndPeriod(Company $company, array $accountIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $accountIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('t')
            ->select(
                'IDENTITY(t.moneyAccount) as accountId',
                'SUM(CASE WHEN t.direction = :inflow THEN t.amount ELSE 0 END) as inflow',
                'SUM(CASE WHEN t.direction = :outflow THEN t.amount ELSE 0 END) as outflow'
            )
            ->join('t.moneyAccount', 'ma')
            ->where('t.company = :company')
            ->andWhere('IDENTITY(t.moneyAccount) IN (:accountIds)')
            ->andWhere('t.currency = ma.currency')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('accountId')
            ->setParameter('company', $company)
            ->setParameter('accountIds', $accountIds)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('inflow', CashDirection::INFLOW)
            ->setParameter('outflow', CashDirection::OUTFLOW);

        // TODO: exclude transfers (isTransfer = true) if the report should show only operational turnovers.

        $result = $qb->getQuery()->getArrayResult();

        $byAccountId = [];
        foreach ($result as $row) {
            $accountId = (string) $row['accountId'];
            $byAccountId[$accountId] = [
                'inflow' => bcadd((string) ($row['inflow'] ?? '0'), '0', 2),
                'outflow' => bcadd((string) ($row['outflow'] ?? '0'), '0', 2),
            ];
        }

        return $byAccountId;
    }

    public function sumInflowByCompanyAndPeriodExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $result = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0) as inflow')
            ->join('t.moneyAccount', 'ma')
            ->where('t.company = :company')
            ->andWhere('t.direction = :inflow')
            ->andWhere('t.currency = ma.currency')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('inflow', CashDirection::INFLOW)
            ->setParameter('isTransfer', false)
            ->getQuery()
            ->getSingleScalarResult();

        return bcadd((string) $result, '0', 2);
    }

    /**
     * @return list<array{date:string,value:string}>
     */
    public function sumInflowByDayExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.occurredAt as date', 'COALESCE(SUM(t.amount), 0) as value')
            ->join('t.moneyAccount', 'ma')
            ->where('t.company = :company')
            ->andWhere('t.direction = :inflow')
            ->andWhere('t.currency = ma.currency')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('inflow', CashDirection::INFLOW)
            ->setParameter('isTransfer', false)
            ->getQuery()
            ->getArrayResult();

        $series = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            if ($date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }

            $series[] = [
                'date' => (string) $date,
                'value' => bcadd((string) ($row['value'] ?? '0'), '0', 2),
            ];
        }

        return $series;
    }


    /**
     * @return array{OPERATING: float, INVESTING: float, FINANCING: float}
     */
    public function sumNetByFlowKindExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('category.flowKind as flowKind', 'COALESCE(SUM(t.amount), 0) as net')
            ->leftJoin('t.cashflowCategory', 'category')
            ->where('t.company = :company')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('category.flowKind IS NOT NULL')
            ->groupBy('category.flowKind')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('isTransfer', false)
            ->getQuery()
            ->getArrayResult();

        $result = [
            CashflowFlowKind::OPERATING->value => 0.0,
            CashflowFlowKind::INVESTING->value => 0.0,
            CashflowFlowKind::FINANCING->value => 0.0,
        ];

        foreach ($rows as $row) {
            $flowKindValue = $row['flowKind'] ?? null;
            $flowKind = $flowKindValue instanceof CashflowFlowKind
                ? $flowKindValue->value
                : (string) $flowKindValue;

            if (!array_key_exists($flowKind, $result)) {
                continue;
            }

            $result[$flowKind] = round((float) ($row['net'] ?? 0.0), 2);
        }

        return $result;
    }

    public function sumOutflowExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0) as outflow')
            ->where('t.company = :company')
            ->andWhere('t.direction = :outflow')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('isTransfer', false)
            ->getQuery()
            ->getSingleScalarResult();

        return abs((float) $result);
    }

    /**
     * @return list<array{categoryId:?string,categoryName:string,sumAbs:float}>
     */
    public function sumOutflowByCategoryExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.cashflowCategory) as categoryId', 'COALESCE(category.name, :uncategorized) as categoryName', 'ABS(COALESCE(SUM(t.amount), 0)) as sumAbs')
            ->leftJoin('t.cashflowCategory', 'category')
            ->where('t.company = :company')
            ->andWhere('t.direction = :outflow')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('category.id', 'category.name')
            ->orderBy('sumAbs', 'DESC')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('isTransfer', false)
            ->setParameter('uncategorized', 'Без категории')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'categoryId' => isset($row['categoryId']) ? (string) $row['categoryId'] : null,
            'categoryName' => (string) ($row['categoryName'] ?? ''),
            'sumAbs' => round((float) ($row['sumAbs'] ?? 0.0), 2),
        ], $rows);
    }

    /**
     * @return list<array{date:string,value:float}>
     */
    public function sumOutflowByDayExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.occurredAt as date', 'COALESCE(SUM(t.amount), 0) as value')
            ->where('t.company = :company')
            ->andWhere('t.direction = :outflow')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('isTransfer', false)
            ->getQuery()
            ->getArrayResult();

        $series = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            if ($date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }

            $series[] = [
                'date' => (string) $date,
                'value' => abs((float) ($row['value'] ?? 0)),
            ];
        }

        return $series;
    }

    public function sumCapexOutflowExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0) as outflow')
            ->leftJoin('t.cashflowCategory', 'category')
            ->where('t.company = :company')
            ->andWhere('t.direction = :outflow')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('category.systemCode = :systemCode')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('isTransfer', false)
            ->setParameter('systemCode', 'CAPEX')
            ->getQuery()
            ->getSingleScalarResult();

        return abs((float) $result);
    }

    /**
     * @return list<array{date:string,value:float}>
     */
    public function sumCapexOutflowByDayExcludeTransfers(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.occurredAt as date', 'COALESCE(SUM(t.amount), 0) as value')
            ->leftJoin('t.cashflowCategory', 'category')
            ->where('t.company = :company')
            ->andWhere('t.direction = :outflow')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('category.systemCode = :systemCode')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('date')
            ->orderBy('date', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->setParameter('outflow', CashDirection::OUTFLOW)
            ->setParameter('isTransfer', false)
            ->setParameter('systemCode', 'CAPEX')
            ->getQuery()
            ->getArrayResult();

        $series = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            if ($date instanceof \DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }

            $series[] = [
                'date' => (string) $date,
                'value' => abs((float) ($row['value'] ?? 0)),
            ];
        }

        return $series;
    }
}
