<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Entity\MoneyAccountDailyBalance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

class MoneyAccountDailyBalanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MoneyAccountDailyBalance::class);
    }

    public function findLastBefore(Company $company, MoneyAccount $account, \DateTimeImmutable $date): ?MoneyAccountDailyBalance
    {
        return $this->createQueryBuilder('b')
            ->where('b.company = :company')
            ->andWhere('b.moneyAccount = :account')
            ->andWhere('b.date < :date')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('date', $date->setTime(0, 0))
            ->orderBy('b.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * @param iterable<array<string,mixed>> $rows
     */
    public function upsertMany(iterable $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $conn = $this->getEntityManager()->getConnection();
        $values = [];
        $params = [];
        $i = 0;
        foreach ($rows as $row) {
            $values[] = '(:id'.$i.', :company'.$i.', :account'.$i.', :date'.$i.', :opening'.$i.', :inflow'.$i.', :outflow'.$i.', :closing'.$i.', :currency'.$i.')';
            $params['id'.$i] = Uuid::uuid4()->toString();
            $params['company'.$i] = $row['company_id'];
            $params['account'.$i] = $row['money_account_id'];
            $params['date'.$i] = $row['date'];
            $params['opening'.$i] = $row['opening_balance'];
            $params['inflow'.$i] = $row['inflow'];
            $params['outflow'.$i] = $row['outflow'];
            $params['closing'.$i] = $row['closing_balance'];
            $params['currency'.$i] = $row['currency'];
            ++$i;
        }
        $sql = 'INSERT INTO money_account_daily_balance (id, company_id, money_account_id, date, opening_balance, inflow, outflow, closing_balance, currency) VALUES '
            .implode(',', $values)
            .' ON CONFLICT (company_id, money_account_id, date) DO UPDATE SET opening_balance = excluded.opening_balance, inflow = excluded.inflow, outflow = excluded.outflow, closing_balance = excluded.closing_balance, currency = excluded.currency';
        $conn->executeStatement($sql, $params);
    }
}
