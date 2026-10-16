<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\DTO\DocumentListDTO;
use App\Finance\DTO\DocumentListTotalsDTO;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;

class DocumentOperationRepository extends ServiceEntityRepository
{
    /** Природа операции, как у регистра ОПиУ: flow статьи, у статьи без потока — знак суммы. */
    private const IS_INCOME = '(category.flow = :income OR (category.flow = :none AND op.amount > 0))';
    private const IS_EXPENSE = '(category.flow = :expense OR (category.flow = :none AND op.amount < 0))';
    /** Порция id документов в IN: длинный список параметров PostgreSQL принимает плохо. */
    private const ID_CHUNK = 5000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentOperation::class);
    }

    /**
     * Компания достижима только через Document — у DocumentOperation своего
     * companyId нет, поэтому join обязателен, иначе выборка не была бы ограничена
     * компанией.
     */
    public function countByCategory(string $companyId, string $categoryId): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->innerJoin('o.document', 'd')
            ->andWhere('d.company = :companyId')
            ->andWhere('o.category = :categoryId')
            ->setParameter('companyId', $companyId)
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Вкладка «Операции»: одна строка — одна операция неудалённого документа компании.
     *
     * Присоединяются только связи «к одному»: документ и его контрагент, проект и основание,
     * статья, проект и контрагент операции. Коллекцию `d.operations` присоединять нельзя:
     * под фильтром по операциям документ гидрировался бы с неполным набором операций.
     *
     * @return Pagerfanta<DocumentOperation>
     */
    public function paginateForList(string $companyId, DocumentListDTO $dto): Pagerfanta
    {
        $allowedLimits = [20, 30, 50];
        $limit = in_array($dto->limit, $allowedLimits, true) ? $dto->limit : $allowedLimits[0];

        $queryBuilder = $this->createFilteredQueryBuilder($companyId, $dto)
            ->addSelect('d', 'category', 'opCp', 'dCp', 'ct', 'opPd', 'dPd')
            ->orderBy('d.date', \SortDirection::Descending)
            // Без id документа и операции порядок при равных датах не определён, и строка
            // могла бы попасть на две страницы.
            ->addOrderBy('d.id', \SortDirection::Ascending)
            ->addOrderBy('op.id', \SortDirection::Ascending);

        // Коллекций в выборке нет — подзапрос по различным id для пагинации не нужен.
        $pager = new Pagerfanta(new QueryAdapter($queryBuilder, false));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage(max(1, $dto->page));

        return $pager;
    }

    /**
     * Итог вкладки «Операции» по всей выборке фильтра: число операций и их документов,
     * доход, расход и результат по валютам. Правила — как у вкладки документов
     * (DocumentRepository::summarizeByCompany), но в итог входят только найденные операции;
     * у marketplace_pl знак — по полной сумме статьи документа (marketplaceBucketSums).
     */
    public function summarizeForList(string $companyId, DocumentListDTO $dto): DocumentListTotalsDTO
    {
        /** @var list<array{currency: string, documents: int|string, operations: int|string, incomeTotal: string|null, expenseTotal: string|null}> $plain */
        $plain = $this->withNatureParameters($this->createFilteredQueryBuilder($companyId, $dto))
            ->select("COALESCE(ct.currency, 'RUB') AS currency")
            ->addSelect('COUNT(DISTINCT d.id) AS documents')
            ->addSelect('COUNT(op.id) AS operations')
            ->addSelect(sprintf('SUM(CASE WHEN d.type <> :marketplace AND %s THEN ABS(op.amount) ELSE 0 END) AS incomeTotal', self::IS_INCOME))
            ->addSelect(sprintf('SUM(CASE WHEN d.type <> :marketplace AND %s THEN ABS(op.amount) ELSE 0 END) AS expenseTotal', self::IS_EXPENSE))
            ->groupBy('currency')
            ->setParameter('marketplace', DocumentType::MARKETPLACE_PL)
            ->getQuery()
            ->getScalarResult();

        /** @var list<array{currency: string, bucket: string, documentId: string, categoryId: string, signedTotal: string}> $found */
        $found = $this->marketplaceBuckets($companyId, $dto)
            ->addSelect("COALESCE(ct.currency, 'RUB') AS currency")
            ->addSelect('d.id AS documentId')
            ->addSelect('category.id AS categoryId')
            ->addGroupBy('currency')
            ->getQuery()
            ->getScalarResult();

        // Знак — по полной сумме статьи и природы в документе, без фильтров списка: фильтр,
        // оставивший одно сторно (+X), не должен превращать его в расход. Вклад найденных
        // операций = −сумма при отрицательной полной сумме (списания), иначе сумма.
        $documentIds = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['documentId'], $found)));
        $fullSums = [];
        foreach (array_chunk($documentIds, self::ID_CHUNK) as $chunk) {
            $fullSums += $this->marketplaceBucketSums($companyId, $chunk);
        }

        $marketplace = [];
        foreach ($found as $row) {
            $sum = bcadd((string) $row['signedTotal'], '0', 2);
            $full = $fullSums[sprintf('%s|%s|%s', $row['documentId'], $row['categoryId'], $row['bucket'])] ?? $sum;
            $marketplace[] = [
                'currency' => $row['currency'],
                'bucket' => $row['bucket'],
                'amount' => -1 === bccomp($full, '0', 2) ? bcmul($sum, '-1', 2) : $sum,
            ];
        }

        return DocumentListTotalsDTO::fromAggregates($plain, $marketplace);
    }

    /**
     * Полные подписанные суммы операций marketplace_pl по (документ, статья, природа) — без
     * фильтров списка: знак суммы решает, со знаком ли операция входит в строку и в итог
     * (списание −X → X, сторно +X → −X), как в регистре ОПиУ и на странице документа.
     *
     * @param list<string> $documentIds
     *
     * @return array<string, string> ключ — «documentId|categoryId|INCOME|EXPENSE»
     */
    public function marketplaceBucketSums(string $companyId, array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        /** @var list<array{documentId: string, categoryId: string, bucket: string, signedTotal: string}> $rows */
        $rows = $this->withNatureParameters($this->createQueryBuilder('op'))
            ->select('d.id AS documentId')
            ->addSelect('category.id AS categoryId')
            ->addSelect(sprintf("CASE WHEN %s THEN 'INCOME' ELSE 'EXPENSE' END AS bucket", self::IS_INCOME))
            ->addSelect('SUM(op.amount) AS signedTotal')
            ->innerJoin('op.document', 'd')
            ->innerJoin('op.category', 'category', Join::WITH, 'IDENTITY(category.company) = :companyId')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.type = :marketplace')
            ->andWhere('d.id IN (:documentIds)')
            ->andWhere(sprintf('(%s OR %s)', self::IS_INCOME, self::IS_EXPENSE))
            ->setParameter('companyId', $companyId)
            ->setParameter('marketplace', DocumentType::MARKETPLACE_PL)
            ->setParameter('documentIds', $documentIds)
            ->groupBy('d.id')
            ->addGroupBy('category.id')
            ->addGroupBy('bucket')
            ->getQuery()
            ->getScalarResult();

        $sums = [];
        foreach ($rows as $row) {
            $sums[sprintf('%s|%s|%s', $row['documentId'], $row['categoryId'], $row['bucket'])] = bcadd((string) $row['signedTotal'], '0', 2);
        }

        return $sums;
    }

    /**
     * Сколько всего операций в каждом документе страницы — без фильтров списка.
     *
     * @param list<string> $documentIds
     *
     * @return array<string, int>
     */
    public function countByDocuments(string $companyId, array $documentIds): array
    {
        if ([] === $documentIds) {
            return [];
        }

        /** @var list<array{documentId: string, operations: int|string}> $rows */
        $rows = $this->createQueryBuilder('op')
            ->select('d.id AS documentId')
            ->addSelect('COUNT(op.id) AS operations')
            ->innerJoin('op.document', 'd')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.id IN (:documentIds)')
            ->setParameter('companyId', $companyId)
            ->setParameter('documentIds', $documentIds)
            ->groupBy('d.id')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['documentId']] = (int) $row['operations'];
        }

        return $counts;
    }

    /**
     * Найденные операции marketplace_pl со статьёй и природой, сгруппированные по документу,
     * статье и природе; вызывающий добавляет свои колонки разреза.
     */
    private function marketplaceBuckets(string $companyId, DocumentListDTO $dto): QueryBuilder
    {
        return $this->withNatureParameters($this->createFilteredQueryBuilder($companyId, $dto))
            ->select(sprintf("CASE WHEN %s THEN 'INCOME' ELSE 'EXPENSE' END AS bucket", self::IS_INCOME))
            ->addSelect('SUM(op.amount) AS signedTotal')
            ->andWhere('d.type = :marketplace')
            ->andWhere(sprintf('(%s OR %s)', self::IS_INCOME, self::IS_EXPENSE))
            ->groupBy('d.id')
            ->addGroupBy('category.id')
            ->addGroupBy('bucket')
            ->setParameter('marketplace', DocumentType::MARKETPLACE_PL);
    }

    private function withNatureParameters(QueryBuilder $queryBuilder): QueryBuilder
    {
        return $queryBuilder
            ->setParameter('income', PLFlow::INCOME)
            ->setParameter('expense', PLFlow::EXPENSE)
            ->setParameter('none', PLFlow::NONE);
    }

    /**
     * Операции неудалённых документов компании под фильтрами списка. Проект, ЦФО и
     * контрагент — значения операции, а если их нет, то документа: так строку видит
     * пользователь и так её разносит регистр ОПиУ.
     */
    private function createFilteredQueryBuilder(string $companyId, DocumentListDTO $dto): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('op')
            ->innerJoin('op.document', 'd')
            // Справочники — только своей компании: FK компанию не несут, и испорченная ссылка на
            // чужую статью, контрагента или проект показала бы чужое название и искалась бы по нему.
            // Такая связь гидрируется как пустая (как в JSON-выгрузке ОПиУ).
            ->leftJoin('op.category', 'category', Join::WITH, 'IDENTITY(category.company) = :companyId')
            ->leftJoin('op.counterparty', 'opCp', Join::WITH, 'IDENTITY(opCp.company) = :companyId')
            ->leftJoin('d.counterparty', 'dCp', Join::WITH, 'IDENTITY(dCp.company) = :companyId')
            ->leftJoin('op.projectDirection', 'opPd', Join::WITH, 'IDENTITY(opPd.company) = :companyId')
            ->leftJoin('d.projectDirection', 'dPd', Join::WITH, 'IDENTITY(dPd.company) = :companyId')
            // Валюта операции — валюта основания ДДС её документа, без основания — рубли.
            ->leftJoin('d.cashTransaction', 'ct', Join::WITH, 'IDENTITY(ct.company) = :companyId')
            ->andWhere('IDENTITY(d.company) = :companyId')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('companyId', $companyId);

        if (null !== $dto->dateFrom) {
            $queryBuilder->andWhere('d.date >= :dateFrom')->setParameter('dateFrom', $dto->dateFrom);
        }

        if (null !== $dto->dateTo) {
            $queryBuilder->andWhere('d.date <= :dateTo')->setParameter('dateTo', $dto->dateTo);
        }

        if (null !== $dto->type) {
            $queryBuilder->andWhere('d.type = :type')->setParameter('type', $dto->type);
        }

        if (null !== $dto->status) {
            $queryBuilder->andWhere('d.status = :status')->setParameter('status', $dto->status);
        }

        if (null !== $dto->number) {
            $queryBuilder->andWhere('LOWER(d.number) LIKE :number')
                ->setParameter('number', self::containsPattern($dto->number));
        }

        if (null !== $dto->counterparty) {
            $queryBuilder->andWhere('LOWER(COALESCE(opCp.name, dCp.name)) LIKE :counterparty')
                ->setParameter('counterparty', self::containsPattern($dto->counterparty));
        }

        if (null !== $dto->q) {
            $queryBuilder->andWhere($queryBuilder->expr()->orX(
                'LOWER(d.description) LIKE :q',
                'LOWER(d.number) LIKE :q',
                'LOWER(COALESCE(opCp.name, dCp.name)) LIKE :q',
                'LOWER(category.name) LIKE :q',
                'LOWER(op.comment) LIKE :q',
            ))->setParameter('q', self::containsPattern($dto->q));
        }

        if (null !== $dto->projectDirectionId) {
            $queryBuilder->andWhere('COALESCE(opPd.id, dPd.id) = :projectDirectionId')
                ->setParameter('projectDirectionId', $dto->projectDirectionId);
        }

        if (null !== $dto->responsibilityCenterId) {
            $queryBuilder->andWhere('COALESCE(op.responsibilityCenterId, d.responsibilityCenterId) = :responsibilityCenterId')
                ->setParameter('responsibilityCenterId', $dto->responsibilityCenterId);
        }

        if (null !== $dto->direction) {
            // Только параметры условия: лишний параметр Doctrine считает ошибкой запроса.
            $isIncome = PLFlow::INCOME === $dto->direction;
            $queryBuilder->andWhere($isIncome ? self::IS_INCOME : self::IS_EXPENSE)
                ->setParameter($isIncome ? 'income' : 'expense', $dto->direction)
                ->setParameter('none', PLFlow::NONE);
        }

        if (null !== $dto->categoryId) {
            $queryBuilder->andWhere('category.id = :categoryId')->setParameter('categoryId', $dto->categoryId);
        }

        if (null !== $dto->amountFrom) {
            $queryBuilder->andWhere('ABS(op.amount) >= :amountFrom')->setParameter('amountFrom', $dto->amountFrom);
        }

        if (null !== $dto->amountTo) {
            $queryBuilder->andWhere('ABS(op.amount) <= :amountTo')->setParameter('amountTo', $dto->amountTo);
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
}
