<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return Document[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.date', 'DESC')
            ->getQuery()
            ->getResult();
    }

}
