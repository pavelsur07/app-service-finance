<?php

namespace App\Cash\Repository\Accounts;

use App\Entity\Company;
use App\Entity\MoneyFund;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoneyFund>
 */
class MoneyFundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoneyFund::class);
    }

    /**
     * @return MoneyFund[]
     */
    public function findByCompany(Company $company): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.company = :company')
            ->setParameter('company', $company)
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
