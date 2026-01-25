<?php

namespace App\Tests\Service\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ClientBank1CImportService;
use App\Company\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use App\Repository\CounterpartyRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

abstract class ClientBank1CImportServiceTestCase extends TestCase
{
    protected EntityManager $em;
    protected ClientBank1CImportService $service;
    protected CashTransactionRepository $transactionRepository;
    protected CounterpartyRepository $counterpartyRepository;
    protected MoneyAccount $account;
    protected Company $company;
    /** @var AccountBalanceService&MockObject */
    protected AccountBalanceService $accountBalanceService;

    protected function setUp(): void
    {
        $config = Setup::createAttributeMetadataConfiguration([__DIR__.'/../../../src/Entity'], true);
        $this->em = EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema([
            $this->em->getClassMetadata(User::class),
            $this->em->getClassMetadata(Company::class),
            $this->em->getClassMetadata(MoneyAccount::class),
            $this->em->getClassMetadata(CashTransaction::class),
            $this->em->getClassMetadata(Counterparty::class),
        ]);

        $registry = new SimpleManagerRegistry($this->em);
        $this->transactionRepository = new CashTransactionRepository($registry);
        $this->counterpartyRepository = new CounterpartyRepository($registry);
        $this->accountBalanceService = $this->createMock(AccountBalanceService::class);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('import@example.com');
        $user->setPassword('password');

        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setName('Test Company');

        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main account', 'RUB');
        $this->account->setAccountNumber('40702810900000000001');

        $this->em->persist($user);
        $this->em->persist($this->company);
        $this->em->persist($this->account);
        $this->em->flush();

        $activeCompanyService = new StubActiveCompanyService($this->company);

        $this->service = new ClientBank1CImportService(
            $activeCompanyService,
            $this->counterpartyRepository,
            $this->transactionRepository,
            $this->em,
            $this->accountBalanceService,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->close();
        }

        parent::tearDown();
    }
}

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

class StubActiveCompanyService extends ActiveCompanyService
{
    public function __construct(private Company $company)
    {
    }

    public function getActiveCompany(): Company
    {
        return $this->company;
    }
}
