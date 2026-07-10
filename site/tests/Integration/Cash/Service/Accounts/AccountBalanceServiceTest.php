<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Exception\BalanceNotAvailableBeforeOpeningDateException;
use App\Cash\Exception\BalanceSnapshotNotFoundException;
use App\Cash\Exception\OpeningBalanceDateInFutureException;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Ramsey\Uuid\Uuid;

final class AccountBalanceServiceTest extends IntegrationTestCase
{
    private AccountBalanceService $balanceService;
    private MoneyAccountDailyBalanceRepository $balanceRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->balanceService = self::getContainer()->get(AccountBalanceService::class);
        $this->balanceRepository = self::getContainer()->get(MoneyAccountDailyBalanceRepository::class);
    }

    public function testUsesOpeningBalanceAndApprovedTransactionRules(): void
    {
        $openingDate = new \DateTimeImmutable('today -2 days');
        [$company, $account] = $this->createAccount('1000.00', $openingDate);

        $beforeOpening = $this->transaction($company, $account, CashDirection::INFLOW, '999.00', $openingDate->modify('-1 day'));
        $transferOutflow = $this->transaction($company, $account, CashDirection::OUTFLOW, '100.00', $openingDate);
        $transferOutflow->setIsTransfer(true);
        $inflow = $this->transaction($company, $account, CashDirection::INFLOW, '-50.00', $openingDate->modify('+1 day'));
        $deletedInflow = $this->transaction($company, $account, CashDirection::INFLOW, '500.00', $openingDate->modify('+1 day'));
        $deletedInflow->markDeleted('test-user', 'excluded from balances');

        foreach ([$beforeOpening, $transferOutflow, $inflow, $deletedInflow] as $transaction) {
            $this->em->persist($transaction);
        }
        $this->em->flush();

        $this->balanceService->recalculateDailyRange(
            $company,
            $account,
            $openingDate->modify('-1 day'),
            $openingDate,
        );

        $rows = $this->rows($company, $account);
        self::assertCount(3, $rows);
        self::assertSame($openingDate->format('Y-m-d'), $rows[0]->getDate()->format('Y-m-d'));
        self::assertBalance($rows[0], '1000.00', '0.00', '100.00', '900.00');
        self::assertBalance($rows[1], '900.00', '50.00', '0.00', '950.00');
        self::assertBalance($rows[2], '950.00', '0.00', '0.00', '950.00');
        self::assertSame('950.00', $this->currentBalance($account));

        $this->balanceService->recalculateDailyRange($company, $account, $openingDate, new \DateTimeImmutable('today'));

        self::assertCount(3, $this->rows($company, $account));
        self::assertNull($this->balanceRepository->findOneBy([
            'company' => $company,
            'moneyAccount' => $account,
            'date' => $openingDate->modify('-1 day'),
        ]));
    }

    public function testRebuildsFromOpeningDateWhenPreviousSnapshotIsMissing(): void
    {
        $openingDate = new \DateTimeImmutable('today -3 days');
        [$company, $account] = $this->createAccount('200.00', $openingDate);
        $this->em->persist($this->transaction($company, $account, CashDirection::INFLOW, '10.00', $openingDate));
        $this->em->flush();

        $this->balanceService->recalculateDailyRange(
            $company,
            $account,
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('today'),
        );

        $rows = $this->rows($company, $account);
        self::assertCount(4, $rows);
        self::assertSame($openingDate->format('Y-m-d'), $rows[0]->getDate()->format('Y-m-d'));
        self::assertBalance($rows[0], '200.00', '10.00', '0.00', '210.00');
        self::assertSame('210.00', $rows[3]->getClosingBalance());
        self::assertSame('210.00', $this->currentBalance($account));
    }

    public function testKeepsDecimalPrecisionForLargeBalances(): void
    {
        $today = new \DateTimeImmutable('today');
        [$company, $account] = $this->createAccount('9999999999999999.90', $today);
        $this->em->persist($this->transaction($company, $account, CashDirection::INFLOW, '0.09', $today));
        $this->em->flush();

        $this->balanceService->recalculateDailyRange($company, $account, $today, $today);

        $rows = $this->rows($company, $account);
        self::assertCount(1, $rows);
        self::assertBalance($rows[0], '9999999999999999.90', '0.09', '0.00', '9999999999999999.99');
        self::assertSame('9999999999999999.99', $this->currentBalance($account));
    }

    public function testUsesOpeningBalanceForSameCalendarDateInDifferentTimezones(): void
    {
        $openingDate = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $sameCalendarDate = new \DateTimeImmutable(
            $openingDate->format('Y-m-d'),
            new \DateTimeZone('America/New_York'),
        );
        [$company, $account] = $this->createAccount('300.00', $openingDate);

        $this->balanceService->recalculateDailyRange(
            $company,
            $account,
            $sameCalendarDate,
            $sameCalendarDate,
        );

        $rows = $this->rows($company, $account);
        self::assertNotEmpty($rows);
        self::assertSame($openingDate->format('Y-m-d'), $rows[0]->getDate()->format('Y-m-d'));
        self::assertSame('300.00', $rows[0]->getOpeningBalance());
    }

    public function testAggregatesMultipleTransactionsInBothDirectionsForOneDay(): void
    {
        $today = new \DateTimeImmutable('today');
        [$company, $account] = $this->createAccount('100.00', $today);

        foreach ([
            $this->transaction($company, $account, CashDirection::INFLOW, '10.25', $today),
            $this->transaction($company, $account, CashDirection::INFLOW, '4.75', $today),
            $this->transaction($company, $account, CashDirection::OUTFLOW, '2.00', $today),
            $this->transaction($company, $account, CashDirection::OUTFLOW, '3.00', $today),
        ] as $transaction) {
            $this->em->persist($transaction);
        }
        $this->em->flush();

        $this->balanceService->recalculateDailyRange($company, $account, $today, $today);

        $rows = $this->rows($company, $account);
        self::assertCount(1, $rows);
        self::assertBalance($rows[0], '100.00', '15.00', '5.00', '110.00');
    }

    public function testReadMethodsClampPeriodAndNeverRecalculate(): void
    {
        $openingDate = new \DateTimeImmutable('today -1 day');
        [$company, $account] = $this->createAccount('100.00', $openingDate);

        $beforeOpening = $this->balanceService->getBalancesForPeriod(
            $company,
            $account,
            $openingDate->modify('-2 days'),
            $openingDate->modify('-1 day'),
        );
        self::assertSame([], $beforeOpening->balances);

        $withoutSnapshots = $this->balanceService->getBalancesForPeriod(
            $company,
            $account,
            $openingDate->modify('-1 day'),
            new \DateTimeImmutable('today'),
        );
        self::assertSame([], $withoutSnapshots->balances);
        self::assertSame([], $this->rows($company, $account));

        $this->balanceService->recalculateDailyRange($company, $account, $openingDate, $openingDate);
        $clamped = $this->balanceService->getBalancesForPeriod(
            $company,
            $account,
            $openingDate->modify('-1 day'),
            $openingDate,
        );
        self::assertCount(1, $clamped->balances);
        self::assertSame($openingDate->format('Y-m-d'), $clamped->balances[0]->date->format('Y-m-d'));
    }

    public function testGetBalanceOnDateUsesTypedAvailabilityExceptions(): void
    {
        $openingDate = new \DateTimeImmutable('today');
        [$company, $account] = $this->createAccount('100.00', $openingDate);

        try {
            $this->balanceService->getBalanceOnDate($company, $account, $openingDate->modify('-1 day'));
            self::fail('Expected exception for a date before the opening balance date.');
        } catch (BalanceNotAvailableBeforeOpeningDateException) {
        }

        $this->expectException(BalanceSnapshotNotFoundException::class);
        $this->balanceService->getBalanceOnDate($company, $account, $openingDate);
    }

    public function testRejectsFutureOpeningBalanceDate(): void
    {
        $tomorrow = new \DateTimeImmutable('tomorrow');
        [$company, $account] = $this->createAccount('100.00', $tomorrow);

        $this->expectException(OpeningBalanceDateInFutureException::class);
        $this->balanceService->recalculateDailyRange($company, $account, $tomorrow, $tomorrow);
    }

    public function testRecalculationIsIdempotentAndRemovesSnapshotsBeforeChangedOpeningDate(): void
    {
        $openingDate = new \DateTimeImmutable('today -2 days');
        [$company, $account] = $this->createAccount('100.00', $openingDate);
        $this->em->persist($this->transaction($company, $account, CashDirection::INFLOW, '10.00', $openingDate));
        $this->em->flush();

        $this->balanceService->recalculateDailyRange($company, $account, $openingDate, new \DateTimeImmutable('today'));
        $firstPass = $this->snapshotData($account);
        $this->balanceService->recalculateDailyRange($company, $account, $openingDate, new \DateTimeImmutable('today'));
        self::assertSame($firstPass, $this->snapshotData($account));

        $newOpeningDate = $openingDate->modify('+1 day');
        $account->setOpeningBalanceDate($newOpeningDate);
        $account->setOpeningBalance('500.00');
        $this->em->flush();

        $this->balanceService->recalculateDailyRange($company, $account, $newOpeningDate, new \DateTimeImmutable('today'));

        $rows = $this->rows($company, $account);
        self::assertCount(2, $rows);
        self::assertSame($newOpeningDate->format('Y-m-d'), $rows[0]->getDate()->format('Y-m-d'));
        self::assertSame('500.00', $rows[0]->getOpeningBalance());
        self::assertSame('500.00', $this->currentBalance($account));
    }

    public function testRecalculationUsesAnAccountScopedAdvisoryLock(): void
    {
        [, $account] = $this->createAccount('100.00', new \DateTimeImmutable('today'));
        $acquired = $this->connection->transactional(function () use ($account): bool {
            $this->balanceRepository->acquireRecalculationLock($account);

            $otherConnection = DriverManager::getConnection($this->connection->getParams());
            try {
                return $otherConnection->fetchOne(
                    'SELECT pg_try_advisory_xact_lock(hashtext(:namespace), hashtext(:account_id))',
                    [
                        'namespace' => 'cash_daily_balance',
                        'account_id' => $account->getId(),
                    ],
                );
            } finally {
                $otherConnection->close();
            }
        });

        self::assertFalse($acquired);
    }

    public function testAggregateQueriesIgnoreStaleSnapshotsBeforeOpeningDate(): void
    {
        $openingDate = new \DateTimeImmutable('today');
        [$company, $account] = $this->createAccount('100.00', $openingDate);
        $staleDate = $openingDate->modify('-1 day');
        $this->em->persist(new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $staleDate,
            '900.00',
            '100.00',
            '0.00',
            '1000.00',
            'RUB',
        ));
        $this->em->flush();

        self::assertSame('0', $this->balanceRepository->getOpeningBalanceForDate($company, $staleDate, $account));
        self::assertSame([], $this->balanceRepository->getClosingTotalsForDate($company, $staleDate));
        self::assertSame([], $this->balanceRepository->getLatestClosingTotalsUpToDate($company, $staleDate));
    }

    /** @return array{Company, MoneyAccount} */
    private function createAccount(string $openingBalance, \DateTimeImmutable $openingDate): array
    {
        $user = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail(Uuid::uuid4().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->build();
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'Main account',
            'RUB',
        );
        $account->setOpeningBalance($openingBalance);
        $account->setOpeningBalanceDate($openingDate);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        return [$company, $account];
    }

    private function transaction(
        Company $company,
        MoneyAccount $account,
        CashDirection $direction,
        string $amount,
        \DateTimeImmutable $date,
    ): CashTransaction {
        return new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            $direction,
            $amount,
            $account->getCurrency(),
            $date,
        );
    }

    /** @return list<MoneyAccountDailyBalance> */
    private function rows(Company $company, MoneyAccount $account): array
    {
        return $this->balanceRepository->findBy(
            ['company' => $company, 'moneyAccount' => $account],
            ['date' => 'ASC'],
        );
    }

    private function currentBalance(MoneyAccount $account): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT current_balance FROM money_account WHERE id = :account_id',
            ['account_id' => $account->getId()],
        );
    }

    /** @return list<array<string, mixed>> */
    private function snapshotData(MoneyAccount $account): array
    {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT date, opening_balance, inflow, outflow, closing_balance, currency
                FROM money_account_daily_balance
                WHERE money_account_id = :account_id
                ORDER BY date
                SQL,
            ['account_id' => $account->getId()],
        );
    }

    private static function assertBalance(
        MoneyAccountDailyBalance $row,
        string $opening,
        string $inflow,
        string $outflow,
        string $closing,
    ): void {
        self::assertSame($opening, $row->getOpeningBalance());
        self::assertSame($inflow, $row->getInflow());
        self::assertSame($outflow, $row->getOutflow());
        self::assertSame($closing, $row->getClosingBalance());
    }
}
