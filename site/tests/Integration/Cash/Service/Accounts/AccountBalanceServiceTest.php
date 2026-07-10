<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Accounts;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
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
        self::assertSame('950.00', $account->getCurrentBalance());

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
        self::assertSame('210.00', $account->getCurrentBalance());
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
        self::assertSame('9999999999999999.99', $account->getCurrentBalance());
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
