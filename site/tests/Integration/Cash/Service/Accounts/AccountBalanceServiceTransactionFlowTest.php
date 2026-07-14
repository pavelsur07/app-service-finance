<?php

namespace App\Tests\Integration\Cash\Service\Accounts;

use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class AccountBalanceServiceTransactionFlowTest extends IntegrationTestCase
{
    private AccountBalanceService $balanceService;
    private CashTransactionService $txService;
    private MoneyAccountDailyBalanceRepository $balanceRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->balanceRepo = self::getContainer()->get(MoneyAccountDailyBalanceRepository::class);
        $this->balanceService = self::getContainer()->get(AccountBalanceService::class);
        $this->txService = self::getContainer()->get(CashTransactionService::class);
    }

    public function testRecalculateBalances(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('t@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test');
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('100');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->flush();

        $dto1 = new CashTransactionDTO();
        $dto1->companyId = $company->getId();
        $dto1->moneyAccountId = $account->getId();
        $dto1->direction = CashDirection::INFLOW;
        $dto1->amount = '50';
        $dto1->currency = 'USD';
        $dto1->occurredAt = new \DateTimeImmutable('2024-01-01');
        $this->txService->add($dto1);

        $dto2 = new CashTransactionDTO();
        $dto2->companyId = $company->getId();
        $dto2->moneyAccountId = $account->getId();
        $dto2->direction = CashDirection::OUTFLOW;
        $dto2->amount = '30';
        $dto2->currency = 'USD';
        $dto2->occurredAt = new \DateTimeImmutable('2024-01-02');
        $this->txService->add($dto2);

        $balances = $this->balanceService->getBalancesForPeriod($company, $account, new \DateTimeImmutable('2024-01-01'), new \DateTimeImmutable('2024-01-02'));
        $this->assertCount(2, $balances->balances);
        $this->assertSame('150.00', $balances->balances[0]->closing);
        $this->assertSame('120.00', $balances->balances[1]->closing);
    }

    public function testRecalculateUsesAccountOpeningBalanceWhenRangeStartsOnOpeningDate(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('opening@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Opening Test');
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Operating', 'USD');
        $account->setOpeningBalance('1000.00');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2025-09-01'));

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);

        $previous = new MoneyAccountDailyBalance(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            new \DateTimeImmutable('2025-08-31'),
            '0.00',
            '0.00',
            '0.00',
            '0.00',
            'USD'
        );
        $this->em->persist($previous);

        $outflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '100.00',
            'USD',
            new \DateTimeImmutable('2025-09-01')
        );
        $inflow = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::INFLOW,
            '20.00',
            'USD',
            new \DateTimeImmutable('2025-09-01')
        );
        $this->em->persist($outflow);
        $this->em->persist($inflow);

        $this->em->flush();

        $rangeDate = new \DateTimeImmutable('2025-09-01');
        $this->balanceService->recalculateDailyRange($company, $account, $rangeDate, $rangeDate);

        $snapshot = $this->balanceRepo->findOneBy([
            'company' => $company,
            'moneyAccount' => $account,
            'date' => $rangeDate,
        ]);

        $this->assertNotNull($snapshot);
        $this->assertSame('1000.00', $snapshot->getOpeningBalance());
        $this->assertSame('920.00', $snapshot->getClosingBalance());
    }
}
