<?php

declare(strict_types=1);

namespace App\Finance\Repository;

use App\Finance\DTO\DocumentListDTO;
use App\Finance\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect('pd')
            ->addSelect('cp')
            ->addSelect('op')
            ->addSelect('opCategory')
            ->leftJoin('d.projectDirection', 'pd')
            ->leftJoin('d.counterparty', 'cp')
            ->leftJoin('d.operations', 'op')
            ->leftJoin('op.category', 'opCategory')
            ->andWhere('d.company = :company')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $dto->company)
            ->orderBy('d.date', 'DESC')
            // Одной даты мало: при равных датах порядок не определён, и один документ
            // может попасть на две страницы, а другой — ни на одну.
            ->addOrderBy('d.id', 'ASC');

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

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        return $pager;
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
            ->orderBy('d.deletedAt', 'DESC')
            ->addOrderBy('d.id', 'ASC');

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
