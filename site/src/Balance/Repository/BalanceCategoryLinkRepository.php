<?php

namespace App\Balance\Repository;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BalanceCategoryLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceCategoryLink::class);
    }

    /**
     * @return BalanceCategoryLink[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.company = :company')
            ->setParameter('company', $company)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BalanceCategoryLink[]
     */
    public function findByCompanyAndCategory(Company $company, BalanceCategory $category): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.company = :company')
            ->andWhere('l.category = :category')
            ->setParameter('company', $company)
            ->setParameter('category', $category)
            ->orderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
