<?php

namespace App\Tests\Service;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Accounts\MoneyAccountDailyBalance;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Accounts\MoneyAccountDailyBalanceRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Transaction\CashTransactionService;
use App\DTO\CashTransactionDTO;
use App\Company\Entity\Company;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class SimpleManagerRegistry implements ManagerRegistry
{
    public function __construct(private EntityManager $em)
    {
    }

    public function getDefaultConnectionName()
    {
        return 'default';
    }

    public function getConnection($name = null)
    {
        return $this->em->getConnection();
    }

    public function getConnections()
    {
        return [$this->em->getConnection()];
    }

    public function getConnectionNames()
    {
        return ['default'];
    }

    public function getDefaultManagerName()
    {
        return 'default';
    }

    public function getManager($name = null)
    {
        return $this->em;
    }

    public function getManagers()
    {
        return ['default' => $this->em];
    }

    public function resetManager($name = null)
    {
        return $this->em;
    }

    public function getAliasNamespace($alias)
    {
        return 'App\\Entity';
    }

    public function getManagerNames()
    {
        return ['default'];
    }

    public function getRepository($persistentObject, $persistentManagerName = null)
    {
        return $this->em->getRepository($persistentObject);
    }

    public function getManagerForClass($class)
    {
        return $this->em;
    }
}

class NullMessageBus implements MessageBusInterface
{
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        return new Envelope($message);
    }
}

class AccountBalanceServiceTest extends TestCase
{
    private EntityManager $em;
    private AccountBalanceService $balanceService;
    private CashTransactionService $txService;
    private MoneyAccountDailyBalanceRepository $balanceRepo;

    protected function setUp(): void
    {
        $config = Setup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        $conn = ['driver' => 'pdo_sqlite', 'memory' => true];
        $this->em = EntityManager::create($conn, $config);
        $schemaTool = new SchemaTool($this->em);
        $classes = [
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(Company::class),
            $this->em->getClassMetadata(MoneyAccount::class),
            $this->em->getClassMetadata(CashTransaction::class),
            $this->em->getClassMetadata(MoneyAccountDailyBalance::class),
        ];
        $schemaTool->createSchema($classes);
        $registry = new SimpleManagerRegistry($this->em);
        $txRepo = new \App\Cash\Repository\Transaction\CashTransactionRepository($registry);
        $this->balanceRepo = new MoneyAccountDailyBalanceRepository($registry);
        $this->balanceService = new AccountBalanceService($txRepo, $this->balanceRepo);
        $this->txService = new CashTransactionService($this->em, $this->balanceService, $txRepo, new NullMessageBus());
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
        $this->assertSame('150', $balances->balances[0]->closing);
        $this->assertSame('120', $balances->balances[1]->closing);
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
