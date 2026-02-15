<?php

namespace App\Tests\Integration\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Accounts\AccountBalanceService;
use App\Cash\Service\Import\ClientBank1CImportService;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Entity\Counterparty;
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

class ClientBank1CImportServiceSoftDeleteTest extends TestCase
{
    private EntityManager $entityManager;
    private Company $company;
    private MoneyAccount $account;
    private CashTransactionRepository $transactionRepository;
    private ClientBank1CImportService $service;

    /** @var AccountBalanceService&MockObject */
    private AccountBalanceService $accountBalanceService;

    protected function setUp(): void
    {
        $config = Setup::createAttributeMetadataConfiguration([__DIR__.'/../../../src/Entity'], true);
        $this->entityManager = EntityManager::create(['driver' => 'pdo_sqlite', 'memory' => true], $config);

        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema([
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Company::class),
            $this->entityManager->getClassMetadata(MoneyAccount::class),
            $this->entityManager->getClassMetadata(CashTransaction::class),
            $this->entityManager->getClassMetadata(Counterparty::class),
        ]);

        $registry = new ImportManagerRegistry($this->entityManager);
        $this->transactionRepository = new CashTransactionRepository($registry);
        $counterpartyRepository = new CounterpartyRepository($registry);

        $this->accountBalanceService = $this->createMock(AccountBalanceService::class);
        $this->accountBalanceService->expects(self::exactly(2))->method('recalculateDailyRange');

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('test-import@example.com');
        $user->setPassword('password');

        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setName('Test Company');

        $this->account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $this->company,
            MoneyAccountType::BANK,
            'Main account',
            'RUB'
        );
        $this->account->setAccountNumber('40702810900000000001');

        $this->entityManager->persist($user);
        $this->entityManager->persist($this->company);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();

        $this->service = new ClientBank1CImportService(
            new StubActiveCompanyService($this->company),
            $counterpartyRepository,
            $this->transactionRepository,
            new ImportLogger($this->entityManager),
            $this->entityManager,
            $this->accountBalanceService,
        );
    }

    public function testImportCreatesNewTransactionWhenOnlySoftDeletedExistsWithSameExternalId(): void
    {
        $firstSummary = $this->service->import([$this->buildRow('Первичное назначение')], $this->account, false);

        self::assertSame(1, $firstSummary['created']);
        self::assertSame(0, $firstSummary['duplicates']);

        /** @var CashTransaction $originalTransaction */
        $originalTransaction = $this->transactionRepository->findAll()[0];
        $originalTransaction->markDeleted('tester', 'delete before reimport');
        $originalDeletedAt = $originalTransaction->getDeletedAt();
        $this->entityManager->flush();

        self::assertNotNull($originalDeletedAt);

        $secondSummary = $this->service->import([$this->buildRow('Новое назначение')], $this->account, false);

        self::assertSame(1, $secondSummary['created']);
        self::assertSame(0, $secondSummary['duplicates']);

        $transactions = $this->transactionRepository->findBy([], ['createdAt' => 'ASC']);
        self::assertCount(2, $transactions);

        $deletedTransactions = array_values(array_filter(
            $transactions,
            static fn (CashTransaction $transaction): bool => $transaction->isDeleted()
        ));
        self::assertCount(1, $deletedTransactions);
        self::assertSame($originalTransaction->getId(), $deletedTransactions[0]->getId());
        self::assertSame($originalDeletedAt?->format(DATE_ATOM), $deletedTransactions[0]->getDeletedAt()?->format(DATE_ATOM));
        self::assertSame('Первичное назначение', $deletedTransactions[0]->getDescription());

        $activeTransactions = array_values(array_filter(
            $transactions,
            static fn (CashTransaction $transaction): bool => !$transaction->isDeleted()
        ));
        self::assertCount(1, $activeTransactions);
        self::assertSame('Новое назначение', $activeTransactions[0]->getDescription());
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(string $purpose): array
    {
        return [
            'docType' => 'Платежное поручение',
            'docNumber' => 'INV-1',
            'docDate' => '2024-01-05',
            'amount' => 1500.25,
            'payerName' => 'ООО Плательщик',
            'payerInn' => '7701000000',
            'payerAccount' => '40702810900000000003',
            'receiverName' => 'ООО Получатель',
            'receiverInn' => '7712000000',
            'receiverAccount' => '40702810900000000004',
            'dateDebit' => '2024-01-05',
            'dateCredit' => null,
            'purpose' => $purpose,
            'direction' => 'outflow',
        ];
    }
}

class ImportManagerRegistry implements ManagerRegistry
{
    public function __construct(private readonly EntityManager $entityManager)
    {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection($name = null): object
    {
        return $this->entityManager->getConnection();
    }

    public function getConnections(): array
    {
        return [$this->entityManager->getConnection()];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager($name = null): object
    {
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->entityManager];
    }

    public function resetManager($name = null): object
    {
        return $this->entityManager;
    }

    public function getAliasNamespace($alias): string
    {
        return 'App\\Entity';
    }

    public function getManagerNames(): array
    {
        return ['default' => 'default'];
    }

    public function getRepository($persistentObject, $persistentManagerName = null): object
    {
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass($class): ?object
    {
        return $this->entityManager;
    }
}

class StubActiveCompanyService extends ActiveCompanyService
{
    public function __construct(private readonly Company $company)
    {
    }

    public function getActiveCompany(): Company
    {
        return $this->company;
    }
}
