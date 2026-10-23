<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\Bank;

use App\Cash\Application\Service\CashTransactionResponsibilityCenterResolver;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Bank\BankConnection;
use App\Cash\Entity\Bank\BankImportCursor;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Cash\Infrastructure\Lock\CashImportLogLease;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Bank\BankImportCursorRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Import\Bank\BankImportService;
use App\Cash\Service\Import\Bank\Provider\BankStatementsProviderInterface;
use App\Cash\Service\Import\ImportLogger;
use App\Company\Application\DTO\FinancialResponsibilityCenterProjectDTO;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class BankImportServiceTest extends TestCase
{
    private string $projectId;
    private string $centerId;
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->projectId = Uuid::uuid4()->toString();
        $this->centerId = Uuid::uuid4()->toString();
    }

    #[DataProvider('currencyProvider')]
    public function testProviderImportValidatesCurrencyAgainstAccount(string $currency, int $expectedTransactions): void
    {
        $persistedTransactions = $this->import([[$this->bankTransaction('bank-tx-1', $currency)]]);

        self::assertCount($expectedTransactions, $persistedTransactions);
        if (1 === $expectedTransactions) {
            self::assertSame($this->projectId, $persistedTransactions[0]->getProjectDirection()?->getId());
            self::assertSame($this->centerId, $persistedTransactions[0]->getResponsibilityCenterId());
        }
    }

    /**
     * Регрессия: строка дня, записанная с первой страницы, до flush() дня не видна в БД — тот же
     * uuid на второй странице создавался повторно, flush() падал на уникальном индексе импорта, и
     * день с курсором повторялся на каждом прогоне.
     */
    public function testSameUuidOnNextPageOfDayIsImportedOnce(): void
    {
        $persistedTransactions = $this->import([
            [$this->bankTransaction('bank-tx-1')],
            [$this->bankTransaction('bank-tx-1'), $this->bankTransaction('bank-tx-2')],
        ]);

        self::assertSame(['bank-tx-1', 'bank-tx-2'], array_map(
            static fn (CashTransaction $transaction): ?string => $transaction->getExternalId(),
            $persistedTransactions,
        ));
    }

    public function testAccountBeingImportedIsSkipped(): void
    {
        $persistedTransactions = $this->import([[$this->bankTransaction('bank-tx-1')]], accountFree: false);

        self::assertSame([], $persistedTransactions);
    }

    /**
     * Сбой выгрузки посреди дня: строки дня до flush() и курсор (новый при первом импорте счёта)
     * отсоединяются до снятия блокировки счёта, иначе flush() журнала импорта записал бы их уже
     * без блокировки.
     */
    public function testUnflushedRowsOfFailedDayAreDetachedBeforeLockRelease(): void
    {
        try {
            $this->import([[$this->bankTransaction('bank-tx-1')], new \RuntimeException('bank is down')]);
            self::fail('Сбой выгрузки должен дойти до вызывающего.');
        } catch (\RuntimeException $exception) {
            self::assertSame('bank is down', $exception->getMessage());
        }

        self::assertSame(['lock', 'persist:bank-tx-1', 'detach:bank-tx-1', 'detach:cursor', 'unlock'], array_values(array_filter(
            $this->events,
            static fn (string $event): bool => !str_starts_with($event, 'flush'),
        )));
    }

    /**
     * Импорт одного счёта за сегодня: страницы дня отдаются по порядку, затем пусто; исключение
     * вместо страницы — сбой выгрузки.
     *
     * @param list<list<array<string, mixed>>|\Throwable> $pages
     *
     * @return list<CashTransaction>
     */
    private function import(array $pages, bool $accountFree = true): array
    {
        $company = new Company(Uuid::uuid4()->toString(), new User(Uuid::uuid4()->toString()));
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Основной', 'RUB');
        $account->setAccountNumber('40702810900000000001');
        $connection = new BankConnection(Uuid::uuid4()->toString(), $company, 'alfa', 'api-key', 'https://bank.test');
        $cursor = new BankImportCursor(Uuid::uuid4()->toString(), $company, 'alfa', '40702810900000000001');
        $cursor->setLastImportedDate(new \DateTimeImmutable('today'));

        $systemProject = new ProjectDirection($this->projectId, $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $persistedTransactions = [];

        $cursorRepository = $this->getMockBuilder(BankImportCursorRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrCreate', 'save'])
            ->getMock();
        $cursorRepository
            ->method('getOrCreate')
            ->willReturn($cursor);

        $moneyAccountRepository = $this->getMockBuilder(MoneyAccountRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneByCompanyAndAccountNumber'])
            ->getMock();
        $moneyAccountRepository
            ->method('findOneByCompanyAndAccountNumber')
            ->willReturn($account);

        $transactionRepository = $this->getMockBuilder(CashTransactionRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findOneByImport'])
            ->getMock();
        $transactionRepository
            ->method('findOneByImport')
            ->willReturn(null);

        $provider = $this->createMock(BankStatementsProviderInterface::class);
        $provider
            ->method('getAccounts')
            ->willReturn([
                'Data' => [
                    'Account' => [[
                        'AccountDetails' => [[
                            'identification' => '40702810900000000001',
                        ]],
                    ]],
                ],
            ]);
        $responses = [];
        foreach ($pages as $index => $transactions) {
            if ($transactions instanceof \Throwable) {
                $responses[] = $transactions;
                continue;
            }
            $response = ['transactions' => $transactions];
            if ($index < \count($pages) - 1) {
                $response['_links'] = [['rel' => 'next', 'href' => 'https://bank.test/next']];
            }
            $responses[] = $response;
        }
        $responses[] = ['transactions' => []];
        $provider
            ->expects($accountFree ? self::atLeastOnce() : self::never())
            ->method('getTransactions')
            ->willReturnCallback(static function () use (&$responses): array {
                $response = array_shift($responses) ?? ['transactions' => []];
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->method('getReference')
            ->with(ProjectDirection::class, $this->projectId)
            ->willReturn($systemProject);
        $entityManager
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedTransactions): void {
                if ($entity instanceof CashTransaction) {
                    $persistedTransactions[] = $entity;
                    $this->events[] = 'persist:'.$entity->getExternalId();
                }
            });
        $entityManager
            ->method('detach')
            ->willReturnCallback(function (object $entity): void {
                if ($entity instanceof CashTransaction) {
                    $this->events[] = 'detach:'.$entity->getExternalId();
                }
                if ($entity instanceof BankImportCursor) {
                    $this->events[] = 'detach:cursor';
                }
            });
        // Коммитит точка входа через callback, сервис сам flush() не вызывает.
        $entityManager->expects(self::never())->method('flush');

        $lockConnection = $this->createMock(Connection::class);
        $lockConnection->method('fetchOne')->willReturnCallback(function (string $sql) use ($accountFree): bool {
            $this->events[] = str_contains($sql, 'unlock') ? 'unlock' : 'lock';

            return $accountFree;
        });

        $service = new BankImportService(
            $cursorRepository,
            $moneyAccountRepository,
            $transactionRepository,
            $entityManager,
            new NullLogger(),
            new ImportLogger($entityManager),
            $this->createResolver($this->projectId, $this->centerId),
            new CashImportAccountLock($lockConnection),
            new CashImportLogLease($this->createMock(Connection::class), new NullLogger()),
        );

        $service->importCompany('alfa', $company, $connection, $provider, function (): void {
            $this->events[] = 'flush';
        });

        return $persistedTransactions;
    }

    /**
     * @return array<string, mixed>
     */
    private function bankTransaction(string $uuid, string $currency = 'RUB'): array
    {
        return [
            'uuid' => $uuid,
            'operationDate' => '2026-07-18T10:00:00+00:00',
            'amount' => [
                'amount' => 1000,
                'currencyName' => $currency,
            ],
            'direction' => 'CREDIT',
            'paymentPurpose' => 'Bank import',
        ];
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function currencyProvider(): iterable
    {
        yield 'normalized matching RUB' => [' rub ', 1];
        yield 'supported mismatch USD' => ['USD', 0];
        yield 'unsupported ABC' => ['ABC', 0];
    }

    private function createResolver(string $projectId, string $centerId): CashTransactionResponsibilityCenterResolver
    {
        $facade = $this->createMock(FinancialResponsibilityCenterFacade::class);
        $facade
            ->method('findGeneralPair')
            ->willReturn(new FinancialResponsibilityCenterProjectDTO($projectId, $centerId));

        return new CashTransactionResponsibilityCenterResolver($facade);
    }
}
