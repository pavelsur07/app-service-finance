<?php

declare(strict_types=1);

namespace App\Cash\Service\Accounts;

use App\Cash\DTO\DailyBalancesDTO;
use App\Cash\DTO\MoneyBalanceDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Exception\BalanceNotAvailableBeforeOpeningDateException;
use App\Cash\Exception\BalanceSnapshotNotFoundException;
use App\Cash\Exception\OpeningBalanceDateInFutureException;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use Doctrine\DBAL\Connection;

final class AccountBalanceService
{
    public function __construct(
        private readonly CashTransactionRepository $txRepo,
        private readonly MoneyAccountDailyBalanceRepository $balanceRepo,
        private readonly Connection $connection,
    ) {
    }

    public function getBalanceOnDate(Company $company, MoneyAccount $account, \DateTimeImmutable $date): MoneyBalanceDTO
    {
        $date = $date->setTime(0, 0);
        if ($date < $account->getOpeningBalanceDate()->setTime(0, 0)) {
            throw new BalanceNotAvailableBeforeOpeningDateException();
        }

        $snapshot = $this->balanceRepo->findOneBy([
            'company' => $company,
            'moneyAccount' => $account,
            'date' => $date,
        ]);
        if (!$snapshot instanceof MoneyAccountDailyBalance) {
            throw new BalanceSnapshotNotFoundException();
        }

        return new MoneyBalanceDTO($snapshot->getDate(), $snapshot->getOpeningBalance(), $snapshot->getInflow(), $snapshot->getOutflow(), $snapshot->getClosingBalance(), $account->getCurrency());
    }

    public function getBalancesForPeriod(Company $company, MoneyAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): DailyBalancesDTO
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $openingDate = $account->getOpeningBalanceDate()->setTime(0, 0);
        if ($to < $openingDate) {
            return new DailyBalancesDTO([], $account->getCurrency());
        }

        if ($from < $openingDate) {
            $from = $openingDate;
        }

        $snapshots = $this->balanceRepo->createQueryBuilder('b')
            ->where('b.company = :c')->andWhere('b.moneyAccount = :a')
            ->andWhere('b.date BETWEEN :f AND :t')
            ->setParameter('c', $company)
            ->setParameter('a', $account)
            ->setParameter('f', $from)
            ->setParameter('t', $to)
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
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $openingDate = $account->getOpeningBalanceDate()->setTime(0, 0);
        $today = (new \DateTimeImmutable('today'))->setTime(0, 0);
        if ($openingDate > $today) {
            throw new OpeningBalanceDateInFutureException();
        }

        $from = max($from, $openingDate);

        $this->connection->transactional(function () use ($company, $account, $from, $to, $openingDate, $today): void {
            $this->balanceRepo->acquireRecalculationLock($account);
            $this->balanceRepo->deleteBeforeOpeningDate($company, $account, $openingDate);
            $this->recalculateLocked($company, $account, $from, $to, $openingDate, $today);
        });
    }

    private function recalculateLocked(
        Company $company,
        MoneyAccount $account,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $openingDate,
        \DateTimeImmutable $today,
    ): void {
        $latestTransactionDate = $this->txRepo->findLatestOccurredAtForAccountFrom($company, $account, $openingDate);
        $latestSnapshotDate = $this->balanceRepo->findLatestDateForAccountFrom($company, $account, $openingDate);
        $to = $this->latestDate($to, $today, $openingDate, $latestTransactionDate, $latestSnapshotDate);

        $prev = null;
        if ($from > $openingDate) {
            $prev = $this->balanceRepo->findLastBefore($company, $account, $from);
            if (null === $prev || $prev->getDate() < $openingDate) {
                $from = $openingDate;
                $prev = null;
            } else {
                $dayAfterPrevious = $prev->getDate()->modify('+1 day')->setTime(0, 0);
                if ($dayAfterPrevious < $from) {
                    $from = $dayAfterPrevious;
                }
            }
        }

        $opening = $from == $openingDate
            ? $this->decimal($account->getOpeningBalance())
            : $this->decimal($prev?->getClosingBalance() ?? $account->getOpeningBalance());

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
            $in = $this->decimal((string) ($map[$key]['inflow'] ?? '0'));
            $out = $this->decimal((string) ($map[$key]['outflow'] ?? '0'));
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

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->balanceRepo->upsertMany($chunk);
        }

        $currentBalance = $this->balanceRepo->findLatestClosingBalanceOnOrBefore(
            $company,
            $account,
            $today,
            $openingDate,
        );
        if (null !== $currentBalance) {
            $this->balanceRepo->updateCurrentBalance($company, $account, $this->decimal($currentBalance));
        }
    }

    private function decimal(string $value): string
    {
        return \bcadd($value, '0', 2);
    }

    private function latestDate(\DateTimeImmutable $first, ?\DateTimeImmutable ...$dates): \DateTimeImmutable
    {
        return array_reduce($dates, static function (\DateTimeImmutable $latest, ?\DateTimeImmutable $date): \DateTimeImmutable {
            return null !== $date && $date > $latest ? $date : $latest;
        }, $first);
    }
}
