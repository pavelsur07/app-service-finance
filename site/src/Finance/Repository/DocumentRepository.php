<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\DTO\DocumentListDTO;
use App\Finance\DTO\DocumentListTotalsDTO;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Enum\PLFlow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ->leftJoin('d.projectDirection', 'pd')
            ->leftJoin('d.operations', 'op')
            ->leftJoin('op.category', 'opCategory')
            ->leftJoin('op.projectDirection', 'opProject')
            ->leftJoin('op.counterparty', 'opCounterparty')
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
     * Итог по всей выборке фильтра, а не по странице: число документов и операций,
     * доход, расход и результат. Знак операции задаёт `flow` её статьи (сумма в БД
     * без знака); операция без статьи или со статьёй `NONE` в суммы не входит, но
     * считается в числе операций — так же считает строка списка.
     *
     * Компания — отдельным параметром: ограничение берётся из него, а не из DTO.
     */
    public function summarizeByCompany(string $companyId, DocumentListDTO $dto): DocumentListTotalsDTO
    {
        $row = $this->createFilteredQueryBuilder($companyId, $dto)
            ->select('COUNT(DISTINCT d.id) AS documents')
            ->addSelect('COUNT(sumOp.id) AS operations')
            ->addSelect('COALESCE(SUM(CASE WHEN sumCategory.flow = :income THEN sumOp.amount ELSE 0 END), 0) AS incomeTotal')
            ->addSelect('COALESCE(SUM(CASE WHEN sumCategory.flow = :expense THEN sumOp.amount ELSE 0 END), 0) AS expenseTotal')
            ->leftJoin('d.operations', 'sumOp')
            ->leftJoin('sumOp.category', 'sumCategory')
            ->setParameter('income', PLFlow::INCOME)
            ->setParameter('expense', PLFlow::EXPENSE)
            ->getQuery()
            ->getSingleResult();

        return new DocumentListTotalsDTO(
            documents: (int) $row['documents'],
            operations: (int) $row['operations'],
            income: (string) $row['incomeTotal'],
            expense: (string) $row['expenseTotal'],
        );
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
            ->leftJoin('d.counterparty', 'cp')
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

        if (null !== $dto->counterparty) {
            $queryBuilder->andWhere('LOWER(cp.name) LIKE :counterparty')
                ->setParameter('counterparty', self::containsPattern($dto->counterparty));
        }

        if (null !== $dto->q) {
            $queryBuilder->andWhere($queryBuilder->expr()->orX(
                'LOWER(d.description) LIKE :q',
                'LOWER(d.number) LIKE :q',
                'LOWER(cp.name) LIKE :q',
                sprintf(
                    'EXISTS (SELECT qOp.id FROM %s qOp JOIN qOp.category qCategory WHERE qOp.document = d AND LOWER(qCategory.name) LIKE :q)',
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

        if (null !== $dto->direction) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT fOp.id FROM %s fOp JOIN fOp.category fCategory WHERE fOp.document = d AND fCategory.flow = :direction)',
                DocumentOperation::class,
            ))->setParameter('direction', $dto->direction);
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

        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect('pd')
            ->leftJoin('d.projectDirection', 'pd')
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

    public function save(Document $document): void
    {
        $this->getEntityManager()->persist($document);
        $this->getEntityManager()->flush();
    }
}
