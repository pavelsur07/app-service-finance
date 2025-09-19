<?php

namespace App\Tests\Service;

use App\Entity\CashflowCategory;
use App\Entity\CashTransaction;
use App\Entity\Company;
use App\Entity\Counterparty;
use App\Entity\MoneyAccount;
use App\Entity\MoneyAccountDailyBalance;
use App\Entity\User;
use App\Enum\CashDirection;
use App\Enum\MoneyAccountType;
use App\Repository\CashTransactionRepository;
use App\Repository\CounterpartyRepository;
use App\Repository\MoneyAccountDailyBalanceRepository;
use App\Service\AccountBalanceService;
use App\Service\Bank1C\Bank1CImportService;
use App\Service\Bank1C\Bank1CStatementParser;
use App\Service\CashTransactionService;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

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

class Bank1CImportServiceTest extends TestCase
{
    private EntityManager $em;
    private Bank1CImportService $importService;
    private Company $company;
    private MoneyAccount $account;

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
        $txRepo = new CashTransactionRepository($registry);
        $cpRepo = new CounterpartyRepository($registry);
        $balanceRepo = new MoneyAccountDailyBalanceRepository($registry);
        $balanceService = new AccountBalanceService($txRepo, $balanceRepo);
        $txService = new CashTransactionService($this->em, $balanceService, $txRepo);
        $parser = new Bank1CStatementParser();
        $this->importService = new Bank1CImportService($parser, $txService, $cpRepo, $txRepo, $this->em);

        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('t@example.com');
        $user->setPassword('pass');
        $this->company = new Company(Uuid::uuid4()->toString(), $user);
        $this->company->setName('Test');
        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main', 'RUB');
        $this->account->setAccountNumber('40702810726140001479');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2025-01-01'));
        $this->em->persist($user);
        $this->em->persist($this->company);
        $this->em->persist($this->account);
        $this->em->flush();
    }

    public function testImportCreatesTransactionsAndIsIdempotent(): void
    {
        $raw = <<<TXT
1CClientBankExchange
ВерсияФормата=1.02
Кодировка=Windows
Отправитель=Test
Получатель=Me

СекцияРасчСчет
НомерСчета=40702810726140001479
КонецРасчСчет

СекцияДокумент=Платежное поручение
Номер=649764
Дата=29.08.2025
Сумма=101449.51
ПлательщикСчет=30302810100180000000
Плательщик=ООО "РВБ"
ПолучательСчет=40702810726140001479
ДатаПоступило=29.08.2025
НазначениеПлатежа=Оплата услуг
КонецДокумента

СекцияДокумент=Платежное поручение
Номер=384
Дата=29.08.2025
Сумма=24000.00
ПлательщикСчет=40702810726140001479
ДатаСписано=29.08.2025
Получатель=ООО "Получатель"
ПолучательСчет=40802810426140004223
НазначениеПлатежа=Оплата аренды
КонецДокумента
КонецФайла
TXT;

        $result1 = $this->importService->import($this->company, $this->account, $raw);
        $this->assertSame(2, $result1->created);

        $txs = $this->em->getRepository(CashTransaction::class)->findBy([], ['occurredAt' => 'ASC']);
        $this->assertCount(2, $txs);
        $in = $txs[0];
        $out = $txs[1];
        $this->assertEquals(CashDirection::INFLOW, $in->getDirection());
        $this->assertEquals('101449.51', $in->getAmount());
        $this->assertEquals('29.08.2025', $in->getOccurredAt()->format('d.m.Y'));
        $this->assertEquals('Оплата услуг', $in->getDescription());
        $this->assertEquals(CashDirection::OUTFLOW, $out->getDirection());
        $this->assertEquals('24000.00', $out->getAmount());
        $this->assertEquals('29.08.2025', $out->getOccurredAt()->format('d.m.Y'));
        $this->assertEquals('Оплата аренды', $out->getDescription());

        $result2 = $this->importService->import($this->company, $this->account, $raw);
        $this->assertSame(0, $result2->created);
        $this->assertSame(2, $result2->duplicates);

        $balances = $this->em->getRepository(MoneyAccountDailyBalance::class)->findBy([
            'moneyAccount' => $this->account,
            'date' => new \DateTimeImmutable('2025-08-29'),
        ]);
        $this->assertCount(1, $balances);
        $bal = $balances[0];
        $this->assertSame('101449.51', $bal->getInflow());
        $this->assertSame('24000.00', $bal->getOutflow());
    }

    public function testImportFailsWhenAccountNumberMismatch(): void
    {
        $raw = <<<TXT
1CClientBankExchange
ВерсияФормата=1.02
Кодировка=Windows
Отправитель=Test
Получатель=Me

СекцияРасчСчет
НомерСчета=40702810726140001478
КонецРасчСчет

СекцияДокумент=Платежное поручение
Номер=1
Дата=01.09.2025
Сумма=100.00
ПлательщикСчет=40702810726140001478
ПолучательСчет=40702810726140001479
НазначениеПлатежа=Test
КонецДокумента
КонецФайла
TXT;

        $result = $this->importService->import($this->company, $this->account, $raw);

        $this->assertSame(0, $result->created);
        $this->assertSame(0, $result->duplicates);
        $this->assertCount(1, $result->errors);
        $this->assertStringContainsString('Номер счёта в файле (', $result->errors[0]);
        $this->assertStringContainsString(') не совпадает с выбранным счётом (', $result->errors[0]);
    }
}
