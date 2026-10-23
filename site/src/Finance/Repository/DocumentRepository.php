<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\DTO\DocumentListDTO;
use App\Finance\DTO\DocumentListTotalsDTO;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return Pagerfanta<Document>
     */
    public function findByCompany(DocumentListDTO $dto): Pagerfanta
    {
        $allowedLimits = [20, 30, 50];
        $limit = in_array($dto->limit, $allowedLimits, true) ? $dto->limit : $allowedLimits[0];
        $page = max(1, $dto->page);

        // Операции и их категории читает сам шаблон списка, когда считает доход и расход
        // по строкам документа. Без fetch-join каждая строка списка добавляет свои запросы.
        $queryBuilder = $this->createFilteredQueryBuilder((string) $dto->company->getId(), $dto)
            ->addSelect('pd')
            ->addSelect('cp')
            ->addSelect('op')
            ->addSelect('opCategory')
            ->addSelect('opProject')
            ->addSelect('opCounterparty')
            ->addSelect('ct')
            ->leftJoin('d.projectDirection', 'pd', Join::WITH, 'IDENTITY(pd.company) = :companyId')
            ->leftJoin('d.operations', 'op')
            // Справочники — только своей компании (см. createFilteredQueryBuilder).
            ->leftJoin('op.category', 'opCategory', Join::WITH, 'IDENTITY(opCategory.company) = :companyId')
            ->leftJoin('op.projectDirection', 'opProject', Join::WITH, 'IDENTITY(opProject.company) = :companyId')
            ->leftJoin('op.counterparty', 'opCounterparty', Join::WITH, 'IDENTITY(opCounterparty.company) = :companyId')
            ->orderBy('d.date', \SortDirection::Descending)
            // Одной даты мало: при равных датах порядок не определён, и один документ
            // может попасть на две страницы, а другой — ни на одну.
            ->addOrderBy('d.id', \SortDirection::Ascending);

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        return $pager;
    }

    /**
     * Итог по всей выборке фильтра, а не по странице: число документов и операций, доход,
     * расход и результат — отдельно по валютам (документ из операции ДДС переносит её сумму
     * без пересчёта; ручной — в рублях). Правила — как у регистра ОПиУ и DocumentPlContribution:
     * операция без статьи не входит; природа — flow статьи, у статьи NONE — знак суммы;
     * обычный документ — модуль каждой операции; marketplace_pl (списание «−», сторно «+») —
     * модуль суммы статьи. Регистр сворачивает marketplace_pl по дню, проекту и ЦФО, здесь —
     * по документу: итог списка — сумма строк, которые видит пользователь.
     *
     * Компания — отдельным параметром: ограничение берётся из него, а не из DTO.
     */
    public function summarizeByCompany(string $companyId, DocumentListDTO $dto): DocumentListTotalsDTO
    {
        $isIncome = '(sumCategory.flow = :income OR (sumCategory.flow = :none AND sumOp.amount > 0))';
        $isExpense = '(sumCategory.flow = :expense OR (sumCategory.flow = :none AND sumOp.amount < 0))';

        /** @var list<array{currency: string, documents: int|string, operations: int|string, incomeTotal: string|null, expenseTotal: string|null}> $plain */
        $plain = $this->createFilteredQueryBuilder($companyId, $dto)
            ->select("COALESCE(ct.currency, 'RUB') AS currency")
            ->addSelect('COUNT(DISTINCT d.id) AS documents')
            ->addSelect('COUNT(sumOp.id) AS operations')
            ->addSelect(sprintf('SUM(CASE WHEN d.type <> :marketplace AND %s THEN ABS(sumOp.amount) ELSE 0 END) AS incomeTotal', $isIncome))
            ->addSelect(sprintf('SUM(CASE WHEN d.type <> :marketplace AND %s THEN ABS(sumOp.amount) ELSE 0 END) AS expenseTotal', $isExpense))
            ->leftJoin('d.operations', 'sumOp')
            ->leftJoin('sumOp.category', 'sumCategory', Join::WITH, 'IDENTITY(sumCategory.company) = :companyId')
            ->groupBy('currency')
            ->setParameter('income', PLFlow::INCOME)
            ->setParameter('expense', PLFlow::EXPENSE)
            ->setParameter('none', PLFlow::NONE)
            ->setParameter('marketplace', DocumentType::MARKETPLACE_PL)
            ->getQuery()
            ->getScalarResult();

        /** @var list<array{currency: string, bucket: string, signedTotal: string}> $marketplace */
        $marketplace = $this->createFilteredQueryBuilder($companyId, $dto)
            ->select("COALESCE(ct.currency, 'RUB') AS currency")
            ->addSelect(sprintf("CASE WHEN %s THEN 'INCOME' ELSE 'EXPENSE' END AS bucket", $isIncome))
            ->addSelect('SUM(sumOp.amount) AS signedTotal')
            ->innerJoin('d.operations', 'sumOp')
            ->innerJoin('sumOp.category', 'sumCategory', Join::WITH, 'IDENTITY(sumCategory.company) = :companyId')
            ->andWhere('d.type = :marketplace')
            ->andWhere(sprintf('(%s OR %s)', $isIncome, $isExpense))
            ->groupBy('d.id')
            ->addGroupBy('sumCategory.id')
            ->addGroupBy('bucket')
            ->addGroupBy('currency')
            ->setParameter('income', PLFlow::INCOME)
            ->setParameter('expense', PLFlow::EXPENSE)
            ->setParameter('none', PLFlow::NONE)
            ->setParameter('marketplace', DocumentType::MARKETPLACE_PL)
            ->getQuery()
            ->getScalarResult();

        // Документ входит со всеми операциями, поэтому сумма статьи здесь полная: вклад — её модуль.
        $marketplace = array_map(static fn (array $row): array => [
            'currency' => $row['currency'],
            'bucket' => $row['bucket'],
            'amount' => -1 === bccomp((string) $row['signedTotal'], '0', 2) ? bcmul((string) $row['signedTotal'], '-1', 2) : bcadd((string) $row['signedTotal'], '0', 2),
        ], $marketplace);

        return DocumentListTotalsDTO::fromAggregates($plain, $marketplace);
    }

    /**
     * Документы компании под фильтрами списка. Условия на операции — через EXISTS, а не
     * через WHERE по присоединённым операциям: список присоединяет их fetch-join'ом, и
     * WHERE по ним гидрировал бы документ с неполным набором операций — суммы строки и
     * итога врали бы.
     */
    private function createFilteredQueryBuilder(string $companyId, DocumentListDTO $dto): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('d')
            // Справочники — только своей компании: FK компанию не несут, и испорченная ссылка на
            // чужого контрагента, статью или проект показала бы чужое название и искалась бы по нему.
            ->leftJoin('d.counterparty', 'cp', Join::WITH, 'IDENTITY(cp.company) = :companyId')
            // Валюта документа — валюта его основания ДДС: нужна строкам списка и итогу.
            ->leftJoin('d.cashTransaction', 'ct', Join::WITH, 'IDENTITY(ct.company) = :companyId')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('companyId', $companyId);

        if (null !== $dto->dateFrom) {
            $queryBuilder->andWhere('d.date >= :dateFrom')
                ->setParameter('dateFrom', $dto->dateFrom);
        }

        if (null !== $dto->dateTo) {
            $queryBuilder->andWhere('d.date <= :dateTo')
                ->setParameter('dateTo', $dto->dateTo);
        }

        if (null !== $dto->type) {
            $queryBuilder->andWhere('d.type = :type')
                ->setParameter('type', $dto->type);
        }

        if (null !== $dto->status) {
            $queryBuilder->andWhere('d.status = :status')
                ->setParameter('status', $dto->status);
        }

        if (null !== $dto->number) {
            $queryBuilder->andWhere('LOWER(d.number) LIKE :number')
                ->setParameter('number', self::containsPattern($dto->number));
        }

        if (null !== $dto->counterpartyId) {
            $queryBuilder->andWhere('IDENTITY(d.counterparty) = :counterpartyId')
                ->setParameter('counterpartyId', $dto->counterpartyId);
        }

        if (null !== $dto->q) {
            $queryBuilder->andWhere($queryBuilder->expr()->orX(
                'LOWER(d.description) LIKE :q',
                'LOWER(d.number) LIKE :q',
                'LOWER(cp.name) LIKE :q',
                sprintf(
                    'EXISTS (SELECT qOp.id FROM %s qOp JOIN qOp.category qCategory WHERE qOp.document = d AND IDENTITY(qCategory.company) = :companyId AND LOWER(qCategory.name) LIKE :q)',
                    DocumentOperation::class,
                ),
            ))->setParameter('q', self::containsPattern($dto->q));
        }

        if (null !== $dto->projectDirectionId) {
            $queryBuilder->andWhere($queryBuilder->expr()->orX(
                'IDENTITY(d.projectDirection) = :projectDirectionId',
                sprintf(
                    'EXISTS (SELECT pOp.id FROM %s pOp WHERE pOp.document = d AND IDENTITY(pOp.projectDirection) = :projectDirectionId)',
                    DocumentOperation::class,
                ),
            ))->setParameter('projectDirectionId', $dto->projectDirectionId);
        }

        if (null !== $dto->responsibilityCenterId) {
            $queryBuilder->andWhere($queryBuilder->expr()->orX(
                'd.responsibilityCenterId = :responsibilityCenterId',
                sprintf(
                    'EXISTS (SELECT rOp.id FROM %s rOp WHERE rOp.document = d AND rOp.responsibilityCenterId = :responsibilityCenterId)',
                    DocumentOperation::class,
                ),
            ))->setParameter('responsibilityCenterId', $dto->responsibilityCenterId);
        }

        // Направление, статья и сумма — условия на одну и ту же операцию: «документ с доходной
        // операцией по статье X на сумму от N», а не три разных операции.
        $operationConditions = [];
        if (null !== $dto->direction) {
            // Природа операции, как в итоге и на вкладке «Операции»: у статьи без потока — знак суммы.
            $operationConditions[] = sprintf(
                '(fCategory.flow = :direction OR (fCategory.flow = :directionNone AND fOp.amount %s 0))',
                PLFlow::INCOME === $dto->direction ? '>' : '<',
            );
            $queryBuilder->setParameter('direction', $dto->direction)->setParameter('directionNone', PLFlow::NONE);
        }
        if (null !== $dto->categoryId) {
            $operationConditions[] = 'fCategory.id = :categoryId';
            $queryBuilder->setParameter('categoryId', $dto->categoryId);
        }
        if (null !== $dto->amountFrom) {
            $operationConditions[] = 'ABS(fOp.amount) >= :amountFrom';
            $queryBuilder->setParameter('amountFrom', $dto->amountFrom);
        }
        if (null !== $dto->amountTo) {
            $operationConditions[] = 'ABS(fOp.amount) <= :amountTo';
            $queryBuilder->setParameter('amountTo', $dto->amountTo);
        }
        if ([] !== $operationConditions) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT fOp.id FROM %s fOp LEFT JOIN fOp.category fCategory WITH IDENTITY(fCategory.company) = :companyId WHERE fOp.document = d AND %s)',
                DocumentOperation::class,
                implode(' AND ', $operationConditions),
            ));
        }

        return $queryBuilder;
    }

    /**
     * Шаблон подстроки для LIKE: спецсимволы LIKE экранируются, иначе «%» из
     * пользовательского ввода превращает фильтр в «показать всё».
     */
    private static function containsPattern(string $value): string
    {
        return '%'.addcslashes(mb_strtolower($value), '%_\\').'%';
    }

    /** Returns active and soft-deleted documents so restore can use the same tenant-safe lookup. */
    public function findByIdAndCompany(string $id, string $companyId): ?Document
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->setParameter('id', $id)
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Pagerfanta<Document>
     */
    public function paginateDeletedByCompany(string $companyId, int $page, int $perPage): Pagerfanta
    {
        $perPage = max(1, min(200, $perPage));

        // Шаблон «Удалённых» считает доход и расход по операциям каждого документа:
        // без fetch-join каждая строка добавляла свои запросы.
        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect('pd', 'cp', 'op', 'opCategory')
            ->leftJoin('d.projectDirection', 'pd')
            ->leftJoin('d.counterparty', 'cp')
            ->leftJoin('d.operations', 'op')
            ->leftJoin('op.category', 'opCategory')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.deletedAt IS NOT NULL')
            ->setParameter('companyId', $companyId)
            ->orderBy('d.deletedAt', \SortDirection::Descending)
            ->addOrderBy('d.id', \SortDirection::Ascending);

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($perPage);
        $pager->setAllowOutOfRangePages(true);
        $pager->setCurrentPage(max(1, $page));

        return $pager;
    }

    /**
     * Сохранённое состояние документа компании, от которого зависит регистр ОПиУ: строка документа
     * под `FOR UPDATE` и его операции. Правка из формы сверяет с ним то, что загрузила форма, —
     * параллельная правка того же документа не перезаписывается молча.
     *
     * @return array{
     *     date: string,
     *     status: string,
     *     type: string,
     *     deleted: bool,
     *     projectDirectionId: ?string,
     *     responsibilityCenterId: ?string,
     *     operations: array<string, array{amount: string, categoryId: ?string, projectDirectionId: ?string, responsibilityCenterId: ?string}>
     * }|null
     */
    public function lockStoredRegisterState(string $documentId, string $companyId): ?array
    {
        $connection = $this->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT date, status, type, deleted_at, project_direction_id, responsibility_center_id FROM documents WHERE id = :id AND company_id = :company_id FOR UPDATE',
            ['id' => $documentId, 'company_id' => $companyId],
        );
        if (false === $row) {
            return null;
        }

        $operations = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, amount, category_id, project_direction_id, responsibility_center_id FROM document_operations WHERE document_id = :id',
            ['id' => $documentId],
        ) as $operation) {
            $operations[(string) $operation['id']] = [
                'amount' => (string) $operation['amount'],
                'categoryId' => null !== $operation['category_id'] ? (string) $operation['category_id'] : null,
                'projectDirectionId' => null !== $operation['project_direction_id'] ? (string) $operation['project_direction_id'] : null,
                'responsibilityCenterId' => null !== $operation['responsibility_center_id'] ? (string) $operation['responsibility_center_id'] : null,
            ];
        }

        return [
            'date' => (string) $row['date'],
            'status' => (string) $row['status'],
            'type' => (string) $row['type'],
            'deleted' => null !== $row['deleted_at'],
            'projectDirectionId' => null !== $row['project_direction_id'] ? (string) $row['project_direction_id'] : null,
            'responsibilityCenterId' => null !== $row['responsibility_center_id'] ? (string) $row['responsibility_center_id'] : null,
            'operations' => $operations,
        ];
    }
}
