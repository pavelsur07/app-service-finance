<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\ProjectDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectDirectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectDirection::class);
    }

    /**
     * @return ProjectDirection[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :company')
            ->setParameter('company', $company)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
