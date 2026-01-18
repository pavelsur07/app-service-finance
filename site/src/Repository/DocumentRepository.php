<?php

namespace App\Repository;

use App\DTO\DocumentListDTO;
use App\Entity\Document;
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
            ->andWhere('d.company = :company')
            ->setParameter('company', $dto->company)
            ->orderBy('d.date', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        return $pager;
    }
}
