<?php

namespace App\Repository;

use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\MoneyAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CashTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashTransaction::class);
    }

    /**
     * @return list<array{date:string,inflow:string,outflow:string}>
     */
    public function sumByDay(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select("DATE(t.occurredAt) as date",
                "SUM(CASE WHEN t.direction = 'INFLOW' THEN t.amount ELSE 0 END) as inflow",
                "SUM(CASE WHEN t.direction = 'OUTFLOW' THEN t.amount ELSE 0 END) as outflow")
            ->where('t.company = :company')
            ->andWhere('t.moneyAccount = :account')
            ->andWhere('t.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(23, 59, 59))
            ->groupBy('date')
            ->orderBy('date', 'ASC');
        return $qb->getQuery()->getArrayResult();
    }
}
