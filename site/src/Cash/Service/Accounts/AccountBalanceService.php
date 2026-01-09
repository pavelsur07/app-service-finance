<?php

namespace App\Cash\Service\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\DTO\DailyBalancesDTO;
use App\DTO\MoneyBalanceDTO;
use App\Entity\Company;
use App\Repository\CashTransactionRepository;

class AccountBalanceService
{
    public function __construct(
        private CashTransactionRepository $txRepo,
        private MoneyAccountDailyBalanceRepository $balanceRepo,
    ) {
    }

    public function getBalanceOnDate(Company $company, MoneyAccount $account, \DateTimeImmutable $date): MoneyBalanceDTO
    {
        $date = $date->setTime(0, 0);
        $snapshot = $this->balanceRepo->findOneBy([
            'company' => $company,
            'moneyAccount' => $account,
            'date' => $date,
        ]);
        if (!$snapshot) {
            $this->recalculateDailyRange($company, $account, $date, $date);
            $snapshot = $this->balanceRepo->findOneBy([
                'company' => $company,
                'moneyAccount' => $account,
                'date' => $date,
            ]);
        }

        return new MoneyBalanceDTO($snapshot->getDate(), $snapshot->getOpeningBalance(), $snapshot->getInflow(), $snapshot->getOutflow(), $snapshot->getClosingBalance(), $account->getCurrency());
    }

    public function getBalancesForPeriod(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): DailyBalancesDTO
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $this->recalculateDailyRange($company, $account, $from, $to);
        $snapshots = $this->balanceRepo->createQueryBuilder('b')
            ->where('b.company = :c')->andWhere('b.moneyAccount = :a')
            ->andWhere('b.date BETWEEN :f AND :t')
            ->setParameters(['c' => $company, 'a' => $account, 'f' => $from, 't' => $to])
            ->orderBy('b.date', 'ASC')
            ->getQuery()->getResult();
        $balances = [];
        foreach ($snapshots as $s) {
            $balances[] = new MoneyBalanceDTO($s->getDate(), $s->getOpeningBalance(), $s->getInflow(), $s->getOutflow(), $s->getClosingBalance(), $account->getCurrency());
        }

        return new DailyBalancesDTO($balances, $account->getCurrency());
    }

    public function recalculateDailyRange(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $prev = $this->balanceRepo->findLastBefore($company, $account, $from);
        $opening = $prev ? $prev->getClosingBalance() : $account->getOpeningBalance();
        // Если пересчёт стартует ровно с даты ввода остатка — opening фиксируем как установленный остаток счёта
        $accountOpeningDate = $account->getOpeningBalanceDate();
        if (null !== $accountOpeningDate) {
            $fromDateOnly = $from->format('Y-m-d');
            $openingDateOnly = $accountOpeningDate->setTime(0, 0)->format('Y-m-d');
            if ($fromDateOnly === $openingDateOnly) {
                $opening = $account->getOpeningBalance();
            }
        }
        $rows = [];
        $txAgg = $this->txRepo->sumByDay($company, $account, $from, $to);
        $map = [];
        foreach ($txAgg as $row) {
            $dateKey = $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : $row['date'];
            $map[$dateKey] = $row;
        }
        $current = clone $from;
        while ($current <= $to) {
            $key = $current->format('Y-m-d');
            $in = $map[$key]['inflow'] ?? '0';
            $out = $map[$key]['outflow'] ?? '0';
            $closing = \bcsub(\bcadd($opening, $in, 2), $out, 2);
            $rows[] = [
                'company_id' => $company->getId(),
                'money_account_id' => $account->getId(),
                'date' => $key,
                'opening_balance' => $opening,
                'inflow' => $in,
                'outflow' => $out,
                'closing_balance' => $closing,
                'currency' => $account->getCurrency(),
            ];
            $opening = $closing;
            $current = $current->modify('+1 day');
        }
        $this->balanceRepo->upsertMany($rows);
    }
}
