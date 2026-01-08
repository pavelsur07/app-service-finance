<?php

namespace App\Tests\Integration;

use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Cash\Service\Transaction\CashTransactionService;
use App\DTO\CashTransactionDTO;
use App\Entity\CashflowCategory;
use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\PaymentPlan;
use App\Entity\PaymentPlanMatch;
use App\Entity\User;
use App\Enum\CashDirection;
use App\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use App\Repository\PaymentPlanMatchRepository;
use App\Repository\PaymentPlanRepository;
use App\Service\PaymentPlan\PaymentPlanService;
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

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection($name = null): object
    {
        return $this->em->getConnection();
    }

    public function getConnections(): array
    {
        return [$this->em->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager($name = null): \Doctrine\Persistence\ObjectManager
    {
        return $this->em;
    }

    public function getManagers(): array
    {
        return ['default' => $this->em];
    }

    public function resetManager($name = null): \Doctrine\Persistence\ObjectManager
    {
        return $this->em;
    }

    public function getAliasNamespace($alias)
    {
        return 'App\\Entity';
    }

    public function getManagerNames(): array
    {
        return ['default'];
    }

    public function getRepository($persistentObject, $persistentManagerName = null): \Doctrine\Persistence\ObjectRepository
    {
        return $this->em->getRepository($persistentObject);
    }

    public function getManagerForClass($class): ?\Doctrine\Persistence\ObjectManager
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

class CashTransactionServiceTest extends TestCase
{
    private EntityManager $em;
    private CashTransactionService $txService;

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
            $this->em->getClassMetadata(CashflowCategory::class),
            $this->em->getClassMetadata(Counterparty::class),
            $this->em->getClassMetadata(PaymentPlan::class),
            $this->em->getClassMetadata(PaymentPlanMatch::class),
        ];
        $schemaTool->createSchema($classes);
        $registry = new SimpleManagerRegistry($this->em);
        $txRepo = new \App\Repository\CashTransactionRepository($registry);
        $balanceRepo = new \App\Repository\MoneyAccountDailyBalanceRepository($registry);
        $balanceService = new AccountBalanceService($txRepo, $balanceRepo);
        $planRepository = new PaymentPlanRepository($registry);
        $planMatchRepository = new PaymentPlanMatchRepository($registry);
        $paymentPlanMatcher = new PaymentPlanMatcher($this->em, $planRepository, $planMatchRepository, new PaymentPlanService());
        $this->txService = new CashTransactionService($this->em, $balanceService, $txRepo, new NullMessageBus(), $paymentPlanMatcher);
    }

    public function testAddPersistsAllFields(): void
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('t@example.com');
        $user->setPassword('pass');
        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Test');
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'USD');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Sales');
        $counterparty = new Counterparty(Uuid::uuid4()->toString(), $company, 'Client', CounterpartyType::CUSTOMER);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($account);
        $this->em->persist($category);
        $this->em->persist($counterparty);
        $this->em->flush();

        $dto = new CashTransactionDTO();
        $dto->companyId = $company->getId();
        $dto->moneyAccountId = $account->getId();
        $dto->direction = CashDirection::INFLOW;
        $dto->amount = '10';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-10');
        $dto->description = 'Test tx';
        $dto->cashflowCategoryId = $category->getId();
        $dto->counterpartyId = $counterparty->getId();

        $tx = $this->txService->add($dto);

        $this->assertSame('Test tx', $tx->getDescription());
        $this->assertSame($category->getId(), $tx->getCashflowCategory()->getId());
        $this->assertSame($counterparty->getId(), $tx->getCounterparty()->getId());
    }
}
