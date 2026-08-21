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

        $queryBuilder = $this->createQueryBuilder('d')
            ->addSelect('pd')
            ->leftJoin('d.projectDirection', 'pd')
            ->andWhere('d.company = :company')
            ->andWhere('d.deletedAt IS NULL')
            ->setParameter('company', $dto->company)
            ->orderBy('d.date', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        return $pager;
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
