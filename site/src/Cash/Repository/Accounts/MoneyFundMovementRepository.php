<?php

namespace App\Cash\Repository\Accounts;

use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Company\Entity\Company;
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

    /**
     * @return array<string,int> fundId => amountMinor
     */
    public function sumFundBalancesUpToDate(Company $company, \DateTimeImmutable $date): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.fund) AS fundId', 'COALESCE(SUM(m.amountMinor), 0) AS amountMinor')
            ->andWhere('m.company = :company')
            ->andWhere('m.occurredAt <= :date')
            ->setParameter('company', $company)
            ->setParameter('date', $date->setTime(23, 59, 59))
            ->groupBy('m.fund')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['fundId']] = (int) $row['amountMinor'];
        }

        return $totals;
    }
}
