<?php

namespace App\Service;

use App\Entity\Company;
use App\Repository\MoneyAccountDailyBalanceRepository;

class AccountBalanceProvider
{
    public function __construct(private readonly MoneyAccountDailyBalanceRepository $dailyBalanceRepository)
    {
    }

    /**
     * @param array<int|string> $accountIds
     *
     * @return array<int|string,string> accountId => closingBalance
     */
    public function getClosingBalancesUpToDate(Company $company, \DateTimeInterface $date, array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $qb = $this->dailyBalanceRepository->createQueryBuilder('b')
            ->innerJoin('b.moneyAccount', 'a')
            ->addSelect('a')
            ->where('b.company = :company')
            ->andWhere('b.date <= :date')
            ->andWhere('a.id IN (:accountIds)')
            ->setParameter('company', $company)
            ->setParameter('date', \DateTimeImmutable::createFromInterface($date)->setTime(0, 0))
            ->setParameter('accountIds', $accountIds)
            ->orderBy('a.id', 'ASC')
            ->addOrderBy('b.date', 'DESC');

        $rows = $qb->getQuery()->getResult();

        $balances = [];
        foreach ($rows as $row) {
            /** @var \App\Entity\MoneyAccountDailyBalance $row */
            $accountId = $row->getMoneyAccount()->getId();
            if (!isset($balances[$accountId])) {
                $balances[$accountId] = $row->getClosingBalance();
            }
        }

        return $balances;
    }
}
