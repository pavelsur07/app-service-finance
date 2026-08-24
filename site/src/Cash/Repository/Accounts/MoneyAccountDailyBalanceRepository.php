<?php

declare(strict_types=1);

namespace App\Cash\Repository\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Company\Entity\Company;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
            ->andWhere('b.date >= :openingDate')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('date', $date->setTime(0, 0))
            ->setParameter('openingDate', $account->getOpeningBalanceDate()->setTime(0, 0))
            ->orderBy('b.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function findLatestDateForAccountFrom(
        Company $company,
        MoneyAccount $account,
        \DateTimeImmutable $from,
    ): ?\DateTimeImmutable {
        $value = $this->createQueryBuilder('b')
            ->select('MAX(b.date)')
            ->where('b.company = :company')
            ->andWhere('b.moneyAccount = :account')
            ->andWhere('b.date >= :from')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('from', $from->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();

        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        return \is_string($value) && '' !== $value
            ? (new \DateTimeImmutable($value))->setTime(0, 0)
            : null;
    }

    public function findLatestClosingBalanceOnOrBefore(
        Company $company,
        MoneyAccount $account,
        \DateTimeImmutable $date,
        \DateTimeImmutable $notBefore,
    ): ?string {
        $row = $this->createQueryBuilder('b')
            ->select('b.closingBalance AS closingBalance')
            ->where('b.company = :company')
            ->andWhere('b.moneyAccount = :account')
            ->andWhere('b.date BETWEEN :notBefore AND :date')
            ->setParameter('company', $company)
            ->setParameter('account', $account)
            ->setParameter('notBefore', $notBefore->setTime(0, 0))
            ->setParameter('date', $date->setTime(0, 0))
            ->orderBy('b.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return \is_array($row) ? (string) $row['closingBalance'] : null;
    }

    public function getOpeningBalanceForDate(
        Company $company,
        \DateTimeInterface $date,
        ?MoneyAccount $account = null,
    ): string {
        $conn = $this->getEntityManager()->getConnection();
        $normalizedDate = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        $params = [
            'company_id' => $company->getId(),
            'date' => $normalizedDate,
        ];
        $types = ['date' => Types::DATE_IMMUTABLE];
        $accountWhere = '';

        if (null !== $account) {
            $accountWhere = ' AND b.money_account_id = :account_id';
            $params['account_id'] = $account->getId();
        }

        $sql = <<<SQL
            SELECT COALESCE(SUM(account_balance), 0) AS total_opening
            FROM (
                SELECT DISTINCT ON (b.money_account_id)
                    CASE
                        WHEN b.date = :date THEN b.opening_balance
                        ELSE b.closing_balance
                    END AS account_balance
                FROM money_account_daily_balance b
                INNER JOIN money_account a ON a.id = b.money_account_id
                WHERE b.company_id = :company_id
                  AND b.date <= :date
                  AND b.date >= a.opening_balance_date
                  {$accountWhere}
                ORDER BY b.money_account_id, b.date DESC
            ) t
        SQL;

        $result = $conn->fetchOne($sql, $params, $types);

        return (string) ($result ?? '0');
    }

    /**
     * @return array<string,string> currency => totalClosing
     */
    public function getClosingTotalsForDate(Company $company, \DateTimeInterface $date): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.currency as currency')
            ->addSelect('COALESCE(SUM(b.closingBalance), 0) as totalClosing')
            ->innerJoin('b.moneyAccount', 'a')
            ->where('b.company = :company')
            ->andWhere('b.date = :date')
            ->andWhere('b.date >= a.openingBalanceDate')
            ->setParameter('company', $company)
            ->setParameter('date', \DateTimeImmutable::createFromInterface($date), Types::DATE_IMMUTABLE)
            ->groupBy('b.currency')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['currency']] = (string) $row['totalClosing'];
        }

        return $result;
    }

    /**
     * Возвращает суммы closingBalance по валютам для последней доступной даты не позже указанной.
     *
     * @return array<string,string> currency => totalClosing (decimal string)
     */
    public function getLatestClosingTotalsUpToDate(Company $company, \DateTimeInterface $date): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT t.currency, COALESCE(SUM(t.closing_balance), 0) AS total_closing
            FROM (
                SELECT DISTINCT ON (b.money_account_id)
                    b.money_account_id,
                    b.currency,
                    b.closing_balance
                FROM money_account_daily_balance b
                INNER JOIN money_account a ON a.id = b.money_account_id
                WHERE b.company_id = :company_id
                  AND b.date <= :date
                  AND b.date >= a.opening_balance_date
                ORDER BY b.money_account_id, b.date DESC
            ) t
            GROUP BY t.currency
        SQL;

        $rows = $conn->fetchAllAssociative(
            $sql,
            [
                'company_id' => $company->getId(),
                'date' => \DateTimeImmutable::createFromInterface($date)->setTime(0, 0),
            ],
            [
                'date' => Types::DATE_IMMUTABLE,
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['currency']] = (string) $row['total_closing'];
        }

        return $result;
    }

    public function acquireRecalculationLock(MoneyAccount $account): void
    {
        $connection = $this->getEntityManager()->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Daily balance recalculation lock requires an active transaction.');
        }

        $connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:namespace), hashtext(:account_id))',
            [
                'namespace' => 'cash_daily_balance',
                'account_id' => $account->getId(),
            ],
        );
    }

    public function deleteBeforeOpeningDate(
        Company $company,
        MoneyAccount $account,
        \DateTimeImmutable $openingDate,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                DELETE FROM money_account_daily_balance
                WHERE company_id = :company_id
                  AND money_account_id = :account_id
                  AND date < :opening_date
                SQL,
            [
                'company_id' => $company->getId(),
                'account_id' => $account->getId(),
                'opening_date' => $openingDate->setTime(0, 0),
            ],
            ['opening_date' => Types::DATE_IMMUTABLE],
        );
    }

    public function updateCurrentBalance(Company $company, MoneyAccount $account, string $balance): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE money_account
                SET current_balance = :balance
                WHERE id = :account_id
                  AND company_id = :company_id
                SQL,
            [
                'balance' => $balance,
                'account_id' => $account->getId(),
                'company_id' => $company->getId(),
            ],
        );
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
