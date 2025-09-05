<?php

namespace App\Tests\Service;

use App\DTO\CashTransactionDTO;
use App\Enum\CashDirection;
use App\Enum\MoneyAccountType;
use App\Enum\CounterpartyType;
use App\Service\AccountBalanceService;
use App\Service\CashTransactionService;
use App\Entity\User;
use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Entity\CashTransaction;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\CashflowCategory;
use App\Entity\Counterparty;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class SimpleManagerRegistry implements ManagerRegistry
{
    public function __construct(private EntityManager $em) {}
    public function getDefaultConnectionName(){return 'default';}
    public function getConnection($name = null){return $this->em->getConnection();}
    public function getConnections(){return [$this->em->getConnection()];}
    public function getConnectionNames(){return ['default'];}
    public function getDefaultManagerName(){return 'default';}
    public function getManager($name = null){return $this->em;}
    public function getManagers(){return ['default' => $this->em];}
    public function resetManager($name = null){return $this->em;}
    public function getAliasNamespace($alias){return 'App\\Entity';}
    public function getManagerNames(){return ['default'];}
    public function getRepository($persistentObject, $persistentManagerName = null){return $this->em->getRepository($persistentObject);}
    public function getManagerForClass($class){return $this->em;}
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
        ];
        $schemaTool->createSchema($classes);
        $registry = new SimpleManagerRegistry($this->em);
        $txRepo = new \App\Repository\CashTransactionRepository($registry);
        $balanceRepo = new \App\Repository\MoneyAccountDailyBalanceRepository($registry);
        $balanceService = new AccountBalanceService($txRepo, $balanceRepo);
        $this->txService = new CashTransactionService($this->em, $balanceService, $txRepo);
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

    public function testClearAllRemovesTransactionsAndRebuildsBalances(): void
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

        $dto = new CashTransactionDTO();
        $dto->companyId = $company->getId();
        $dto->moneyAccountId = $account->getId();
        $dto->direction = CashDirection::INFLOW;
        $dto->amount = '50';
        $dto->currency = 'USD';
        $dto->occurredAt = new \DateTimeImmutable('2024-01-02');
        $this->txService->add($dto);

        $this->txService->clearAll();

        $this->assertCount(0, $this->em->getRepository(CashTransaction::class)->findAll());
        $balances = $this->em->getRepository(MoneyAccountDailyBalance::class)->findAll();
        $this->assertCount(1, $balances);
        $balance = $balances[0];
        $this->assertSame('100', $balance->getOpeningBalance());
        $this->assertSame('0', $balance->getInflow());
        $this->assertSame('0', $balance->getOutflow());
        $this->assertSame('100.00', $balance->getClosingBalance());
    }
}
