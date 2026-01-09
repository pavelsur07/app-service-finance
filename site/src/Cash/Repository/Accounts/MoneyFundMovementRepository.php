<?php

namespace App\Cash\Repository\Accounts;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MoneyFundMovement>
 */
class MoneyFundMovementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoneyFundMovement::class);
    }

    public function deleteByFund(Company $company, MoneyFund $fund): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.company = :company')
            ->andWhere('m.fund = :fund')
            ->setParameter('company', $company)
            ->setParameter('fund', $fund)
            ->getQuery()
            ->execute();
    }
}
