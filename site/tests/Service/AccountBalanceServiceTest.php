<?php

namespace App\Tests\Service;

use App\DTO\CashTransactionDTO;
use App\Enum\CashDirection;
use App\Service\AccountBalanceService;
use App\Service\CashTransactionService;
use App\Entity\User;
use App\Entity\Company;
use App\Entity\MoneyAccount;
use App\Enum\MoneyAccountType;
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

class AccountBalanceServiceTest extends TestCase
{
    private EntityManager $em;
    private AccountBalanceService $balanceService;
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
            $this->em->getClassMetadata(\App\Entity\CashTransaction::class),
            $this->em->getClassMetadata(\App\Entity\MoneyAccountDailyBalance::class),
        ];
        $schemaTool->createSchema($classes);
        $registry = new SimpleManagerRegistry($this->em);
        $txRepo = new \App\Repository\CashTransactionRepository($registry);
        $balanceRepo = new \App\Repository\MoneyAccountDailyBalanceRepository($registry);
        $this->balanceService = new AccountBalanceService($txRepo, $balanceRepo);
        $this->txService = new CashTransactionService($this->em, $this->balanceService, $txRepo);
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
}
