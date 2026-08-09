<?php

namespace App\Cash\Repository\Transaction;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

class CashTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransaction::class);
    }

    public function findOneByIdAndCompanyId(string $id, string $companyId): ?CashTransaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string> $ids
     *
     * @return list<CashTransaction>
     */
    public function findActiveByIdsAndCompanyId(array $ids, string $companyId): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('moneyAccount')
            ->innerJoin('t.moneyAccount', 'moneyAccount')
            ->andWhere('t.id IN (:ids)')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getResult();
    }

    /** @return iterable<CashTransaction> */
    public function iterateForAutoRulePreview(
        Company $company,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): iterable {
        $query = $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->andWhere('t.occurredAt >= :from')
            ->andWhere('t.occurredAt <= :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from->setTime(0, 0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->innerJoin('t.moneyAccount', 'moneyAccount')
            ->addSelect('moneyAccount')
            ->leftJoin('t.counterparty', 'counterparty')
            ->addSelect('counterparty')
            ->leftJoin('t.cashflowCategory', 'cashflowCategory')
            ->addSelect('cashflowCategory')
            ->leftJoin('t.projectDirection', 'projectDirection')
            ->addSelect('projectDirection')
            ->orderBy('t.occurredAt', 'DESC')
            ->getQuery();
        $query->setHint(Query::HINT_READ_ONLY, true);

        return $query->toIterable();
    }

    public function maxUpdatedAtForCompany(Company $company): ?\DateTimeImmutable
    {
        $maxUpdatedAt = $this->createQueryBuilder('t')
            ->select('MAX(t.updatedAt)')
            ->andWhere('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$maxUpdatedAt instanceof \DateTimeInterface) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($maxUpdatedAt);
    }

    /**
     * @return list<array{date:string,inflow:string,outflow:string}>
     */
    public function sumByDay(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.occurredAt as date',
                "SUM(CASE WHEN t.direction = 'INFLOW' THEN ABS(t.amount) ELSE 0 END) as inflow",
                "SUM(CASE WHEN t.direction = 'OUTFLOW' THEN ABS(t.amount) ELSE 0 END) as outflow"
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

    public function findLatestOccurredAtForAccountFrom(
        Company $company,
        MoneyAccount $account,
        \DateTimeImmutable $from,
    ): ?\DateTimeImmutable {
        $value = $this->createQueryBuilder('t')
            ->select('MAX(t.occurredAt)')
            ->where('t.company = :company')
            ->andWhere('t.moneyAccount = :account')
            ->andWhere('t.occurredAt >= :from')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('from', $from->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        return \is_string($value) && '' !== $value
            ? (new \DateTimeImmutable($value))->setTime(0, 0)
            : null;
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

    public function findOneByCompanyImportSourceExternalId(string $companyId, string $importSource, string $externalId): ?CashTransaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.importSource = :importSource')
            ->andWhere('t.externalId = :externalId')
            ->andWhere('t.deletedAt IS NULL')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('importSource', $importSource)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByImport(string $companyId, string $source, string $externalId): ?CashTransaction
    {
        return $this->findOneByCompanyImportSourceExternalId($companyId, $source, $externalId);
    }

    public function findAnyByCompanyImportSourceExternalId(string $companyId, string $importSource, string $externalId): ?CashTransaction
    {
        return $this->createQueryBuilder('t')
            ->andWhere('IDENTITY(t.company) = :companyId')
            ->andWhere('t.importSource = :importSource')
            ->andWhere('t.externalId = :externalId')
            ->setMaxResults(1)
            ->setParameter('companyId', $companyId)
            ->setParameter('importSource', $importSource)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findIdByCompanyImportSourceExternalIdDbal(string $companyId, string $importSource, string $externalId): ?string
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM cash_transaction WHERE company_id = :companyId AND import_source = :importSource AND external_id = :externalId AND deleted_at IS NULL LIMIT 1',
            [
                'companyId' => $companyId,
                'importSource' => $importSource,
                'externalId' => $externalId,
            ],
        );

        if (false === $id || null === $id) {
            return null;
        }

        return (string) $id;
    }

    public function findAnyIdByCompanyImportSourceExternalIdDbal(string $companyId, string $importSource, string $externalId): ?string
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM cash_transaction WHERE company_id = :companyId AND import_source = :importSource AND external_id = :externalId LIMIT 1',
            [
                'companyId' => $companyId,
                'importSource' => $importSource,
                'externalId' => $externalId,
            ],
        );

        if (false === $id || null === $id) {
            return null;
        }

        return (string) $id;
    }

    public function findActiveByCompanyAccountExternalId(
        Company $company,
        MoneyAccount $account,
        string $externalId,
    ): ?CashTransaction {
        return $this->createQueryBuilder('t')
            ->where('t.company = :company')
            ->andWhere('t.moneyAccount = :account')
            ->andWhere('t.externalId = :externalId')
            ->andWhere('t.deletedAt IS NULL')
            ->setMaxResults(1)
            ->setParameter('company', $company)
            ->setParameter('account', $account)
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
        $pager = new Pagerfanta(new QueryAdapter($this->createFilteredQueryBuilder($company, $filters)));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage($page);

        return $pager;
    }

    /**
     * Тот же набор фильтров, что и на экране, но без пагинации — для экспорта.
     *
     * Отдаём только нужные колонки: сущности не гидрируются, identity map не растёт,
     * поэтому память не зависит от размера выгрузки.
     *
     * @param array<string, string|null> $filters
     *
     * @return iterable<array{
     *     occurredAt: \DateTimeImmutable,
     *     direction: CashDirection,
     *     amount: string,
     *     accountName: string,
     *     categoryName: ?string,
     *     description: ?string,
     *     counterpartyName: ?string
     * }>
     */
    public function iterateByCompanyWithFilters(Company $company, array $filters): iterable
    {
        $qb = $this->createFilteredQueryBuilder($company, $filters)
            ->select(
                't.occurredAt AS occurredAt',
                't.direction AS direction',
                // Решение D4: одна строка выгрузки на строку разбивки. Без COALESCE
                // транзакция без категории ушла бы в файл с пустой суммой.
                'COALESCE(split.amount, t.amount) AS amount',
                'moneyAccount.name AS accountName',
                'cashflowCategory.name AS categoryName',
                't.description AS description',
                'counterparty.name AS counterpartyName',
            )
            ->innerJoin('t.moneyAccount', 'moneyAccount')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'cashflowCategory')
            ->leftJoin('t.counterparty', 'counterparty');

        if ($filters['categoryId'] ?? null) {
            // Фильтр из createFilteredQueryBuilder отбирает транзакции, а здесь строки
            // ещё и присоединены: без этого ограничения выгрузка по одной категории
            // вернула бы и остальные строки разбитой транзакции.
            $qb->andWhere('split.cashflowCategory = :cat');
        }

        return $qb
            ->getQuery()
            // toIterable() здесь недоступен: Doctrine запрещает итерировать запрос
            // с join коллекции, а одна строка выгрузки на строку разбивки (решение D4)
            // без такого join не получается.
            //
            // Потолок: весь результат держится в памяти. Для нынешних объёмов это
            // единицы мегабайт на семь скалярных колонок. Если у компании появятся
            // сотни тысяч транзакций за период, метод надо переписать на DBAL
            // с iterateAssociative() и явным LEFT JOIN.
            ->getArrayResult();
    }

    /**
     * Инициализирует коллекции строк разбивки для уже загруженной страницы списка.
     *
     * Шаблоны выводят категории из строк, а ленивая OneToMany дала бы по запросу
     * на каждую транзакцию. Fetch-join прямо в пагинированный запрос делать нельзя:
     * join коллекции вместе с LIMIT режет не транзакции, а строки. Поэтому второй
     * шаг по идентификаторам уже полученной страницы — он подтягивает коллекции
     * тех же управляемых сущностей одним запросом.
     *
     * @param list<CashTransaction> $transactions
     */
    public function warmSplits(array $transactions): void
    {
        $ids = array_values(array_filter(array_map(
            static fn (CashTransaction $transaction): ?string => $transaction->getId(),
            $transactions,
        )));

        if ([] === $ids) {
            return;
        }

        $this->createQueryBuilder('t')
            ->addSelect('warmSplit', 'warmCategory')
            ->leftJoin('t.splits', 'warmSplit')
            ->leftJoin('warmSplit.cashflowCategory', 'warmCategory')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string|null> $filters
     */
    private function createFilteredQueryBuilder(Company $company, array $filters): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.company = :company')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('t.occurredAt', 'DESC');

        if ($filters['dateFrom'] ?? null) {
            $qb->andWhere('t.occurredAt >= :df')->setParameter('df', new \DateTimeImmutable($filters['dateFrom']));
        }
        if ($filters['dateTo'] ?? null) {
            $qb->andWhere('t.occurredAt <= :dt')->setParameter('dt', new \DateTimeImmutable($filters['dateTo']));
        }
        if ($filters['accountId'] ?? null) {
            $qb->andWhere('t.moneyAccount = :acc')->setParameter('acc', $filters['accountId']);
        }
        if ($filters['categoryId'] ?? null) {
            // Фильтр по строкам разбивки: транзакция попадает в выдачу, если её содержит
            // хотя бы одна строка, и показывается суммой именно этой строки.
            $qb->andWhere('EXISTS (SELECT 1 FROM App\\Cash\\Entity\\Transaction\\CashTransactionSplit fs WHERE fs.cashTransaction = t AND fs.cashflowCategory = :cat)')
                ->setParameter('cat', $filters['categoryId']);
        }
        if ($filters['counterpartyId'] ?? null) {
            $qb->andWhere('t.counterparty = :cp')->setParameter('cp', $filters['counterpartyId']);
        }
        if ($filters['direction'] ?? null) {
            $qb->andWhere('t.direction = :dir')->setParameter('dir', $filters['direction']);
        }
        if ($filters['amountMin'] ?? null) {
            $qb->andWhere('t.amount >= :amin')->setParameter('amin', $filters['amountMin']);
        }
        if ($filters['amountMax'] ?? null) {
            $qb->andWhere('t.amount <= :amax')->setParameter('amax', $filters['amountMax']);
        }
        if ($filters['q'] ?? null) {
            $qb->andWhere('t.description LIKE :q')->setParameter('q', '%'.$filters['q'].'%');
        }

        return $qb;
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

        // isTransfer is metadata only; account turnover follows INFLOW/OUTFLOW for every transaction.

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
            ->select('category.flowKind as flowKind', 'COALESCE(SUM(split.amount), 0) as net')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
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
            // COALESCE по сумме обязателен: у транзакции без категории строк нет, и без него
            // корзина «без категории» показывала бы ноль вместо реального оборота.
            ->select('IDENTITY(split.cashflowCategory) as categoryId', 'COALESCE(category.name, :uncategorized) as categoryName', 'ABS(COALESCE(SUM(COALESCE(split.amount, t.amount)), 0)) as sumAbs')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
            ->where('t.company = :company')
            ->andWhere('t.direction = :outflow')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->andWhere('t.isTransfer = :isTransfer')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('split.cashflowCategory', 'category.name')
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
            ->select('COALESCE(SUM(split.amount), 0) as outflow')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
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
            ->select('t.occurredAt as date', 'COALESCE(SUM(split.amount), 0) as value')
            ->leftJoin('t.splits', 'split')
            ->leftJoin('split.cashflowCategory', 'category')
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
