<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\DTO\BankStatementDayDTO;
use App\BankSync\Application\DTO\BankStatementLineDTO;
use App\BankSync\Domain\BankAccess;
use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Entity\BankImportCursor;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Infrastructure\Bank\BankProviderRegistry;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Message\SyncBankConnectionMessage;
use App\BankSync\MessageHandler\SyncBankConnectionHandler;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Facade\CashFacade;
use App\Cash\Infrastructure\Lock\CashImportAccountLock;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\FinancialResponsibilityCenterProject;
use App\Company\Entity\ProjectDirection;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Полный путь загрузки выписки: handler, настоящий CashFacade и БД; банк — фейковый адаптер.
 */
final class SyncBankConnectionHandlerTest extends IntegrationTestCase
{
    private const ACCOUNT_NUMBER = '40702810900000000001';
    private const TOKEN = 'handler-secret-token';

    private string $companyId;
    private MoneyAccount $account;
    private BankConnection $bankConnection;
    private FakeBankProvider $bank;
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-sync-'.$this->companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $this->account = MoneyAccountBuilder::aMoneyAccount()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withCurrency('RUB')
            ->build();
        $this->account->setAccountNumber(self::ACCOUNT_NUMBER);
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Общий', ProjectDirection::CODE_GENERAL);
        $center = new FinancialResponsibilityCenter($this->companyId, FinancialResponsibilityCenter::CODE_GENERAL, FinancialResponsibilityCenter::NAME_GENERAL);
        $this->bankConnection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($this->companyId)
            ->withCredentials($this->codec()->encryptStaticToken(self::TOKEN))
            ->build();

        foreach ([$owner, $company, $this->account, $project, $center, new FinancialResponsibilityCenterProject($this->companyId, $project, $center), $this->bankConnection] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $this->bank = new FakeBankProvider([new BankAccountDTO(self::ACCOUNT_NUMBER), new BankAccountDTO('40702810900000009999')]);
        $this->logs = new TestHandler();
    }

    public function testFirstRunLoadsWeekByDaysRecalculatesBalancesAndMovesCursor(): void
    {
        $this->bank->days[$this->daysAgo(3)] = [$this->line('in-1', BankDirection::CREDIT, '1000.00', $this->daysAgo(3))];
        $this->bank->days[$this->daysAgo(0)] = [$this->line('out-1', BankDirection::DEBIT, '250.00', $this->daysAgo(0))];

        $this->handle();

        self::assertSame(
            array_map(fn (int $daysAgo): string => $this->daysAgo($daysAgo), range(SyncBankConnectionHandler::FIRST_RUN_DAYS, 0, -1)),
            $this->bank->fetchedDays,
        );
        self::assertSame(self::TOKEN, $this->bank->lastToken);
        self::assertSame(2, $this->countTransactions());
        self::assertSame($this->daysAgo(0), $this->cursorDate());
        self::assertSame('750.00', $this->closingBalance($this->daysAgo(0)), 'остатки пересчитаны по загруженным строкам');

        $log = $this->requireLastImportLog();
        self::assertSame('bank:alfa', $log['source']);
        self::assertNotNull($log['finished_at']);
        self::assertSame([2, 0, 0], [(int) $log['created_count'], (int) $log['skipped_duplicates'], (int) $log['errors_count']]);
        self::assertSame(1, json_decode((string) $log['file_name'], true, 512, \JSON_THROW_ON_ERROR)['accounts_matched']);
        $this->assertNoSensitiveDataInLogs();
    }

    public function testRepeatedRunStartsDayBeforeCursorAndAddsNoDuplicates(): void
    {
        $this->bank->days[$this->daysAgo(1)] = [$this->line('in-1', BankDirection::CREDIT, '10.00', $this->daysAgo(1))];
        $this->handle();
        $this->bank->fetchedDays = [];

        $this->handle();

        self::assertSame([$this->daysAgo(1), $this->daysAgo(0)], $this->bank->fetchedDays);
        self::assertSame(1, $this->countTransactions());
        // started_at хранится до секунды: журналы двух прогонов сравниваем набором, а не порядком.
        self::assertSame([0, 1], array_map('intval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT skipped_duplicates FROM import_log WHERE company_id = :companyId ORDER BY skipped_duplicates',
            ['companyId' => $this->companyId],
        )));
    }

    public function testFailureOnSecondDayKeepsFirstDayAndClosesLogWithError(): void
    {
        $cursor = new BankImportCursor($this->companyId, BankCode::ALFA, self::ACCOUNT_NUMBER);
        $cursor->markImported(new \DateTimeImmutable($this->daysAgo(2)));
        $this->em->persist($cursor);
        $this->em->flush();

        $this->bank->days[$this->daysAgo(3)] = [$this->line('in-1', BankDirection::CREDIT, '10.00', $this->daysAgo(3))];
        $this->bank->failures[$this->daysAgo(2)] = new BankApiUnavailableException('alfa', 503);
        $this->bank->days[$this->daysAgo(1)] = [$this->line('in-2', BankDirection::CREDIT, '20.00', $this->daysAgo(1))];

        try {
            $this->handle();
            self::fail('Temporary bank failure must be rethrown for retry.');
        } catch (BankApiUnavailableException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        }

        self::assertSame(1, $this->countTransactions(), 'первый день сохранён, третий не загружался');
        self::assertSame($this->daysAgo(3), $this->cursorDate());
        $log = $this->requireLastImportLog();
        self::assertNotNull($log['finished_at']);
        self::assertSame([1, 1], [(int) $log['created_count'], (int) $log['errors_count']]);
        self::assertSame(BankApiUnavailableException::class, json_decode((string) $log['file_name'], true, 512, \JSON_THROW_ON_ERROR)['error']);
    }

    /**
     * Регрессия: новый курсор, переданный в persist() до HTTP, сохранялся коммитом журнала
     * после сбоя — уже без блокировки счёта.
     */
    public function testFirstDayHttpFailureDoesNotStoreNewCursor(): void
    {
        $this->bank->failures[$this->daysAgo(SyncBankConnectionHandler::FIRST_RUN_DAYS)] = new BankApiUnavailableException('alfa', 503);

        try {
            $this->handle();
            self::fail('Temporary bank failure must be rethrown for retry.');
        } catch (BankApiUnavailableException) {
        }

        self::assertNotNull($this->requireLastImportLog()['finished_at']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM bank_sync_import_cursor WHERE company_id = :companyId',
            ['companyId' => $this->companyId],
        ));
    }

    public function testDatabaseFailureInsideDayRollsBackDayAndCursor(): void
    {
        // Сумма, которую БД не примет: сбой внутри транзакции дня закрывает EntityManager.
        $this->bank->days[$this->daysAgo(7)] = [
            $this->line('ok-1', BankDirection::CREDIT, '10.00', $this->daysAgo(7)),
            $this->line('bad-1', BankDirection::CREDIT, 'not-a-number', $this->daysAgo(7)),
        ];

        try {
            $this->handle();
            self::fail('Database failure must be rethrown.');
        } catch (\Throwable $exception) {
            self::assertNotInstanceOf(UnrecoverableMessageHandlingException::class, $exception);
        }

        self::assertFalse($this->em->isOpen());
        self::assertSame(0, $this->countTransactions(), 'строки дня откатились целиком');
        self::assertNull($this->cursorDate());
    }

    public function testBankRejectionIsUnrecoverable(): void
    {
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle();
    }

    public function testMissingConnectionIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new SyncBankConnectionMessage(Uuid::uuid4()->toString(), $this->bankConnection->getId()));
    }

    public function testUnknownBankIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handler(new BankProviderRegistry([]))(new SyncBankConnectionMessage($this->companyId, $this->bankConnection->getId()));
    }

    public function testUndecryptableCredentialsAreUnrecoverableWithoutCallingBank(): void
    {
        $this->bankConnection->replaceCredentials('{"broken":true}', 'v1');
        $this->em->flush();

        try {
            $this->handle();
            self::fail('Credentials failure must be unrecoverable.');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertSame([], $this->bank->fetchedDays);
        self::assertFalse($this->bank->accountsListed);
    }

    public function testInactiveConnectionIsSkipped(): void
    {
        $this->bankConnection->changeSettings($this->bankConnection->getBaseUrl(), false);
        $this->em->flush();

        $this->handle();

        self::assertFalse($this->bank->accountsListed);
        self::assertFalse($this->lastImportLog());
    }

    public function testBusyAccountIsSkippedWithWarning(): void
    {
        // Блокировку счёта держит другая сессия — как параллельный файловый импорт.
        $otherSession = DriverManager::getConnection($this->em->getConnection()->getParams());
        try {
            self::assertTrue((new CashImportAccountLock($otherSession))->tryAcquire($this->companyId, (string) $this->account->getId()));
            $this->handle();
        } finally {
            $otherSession->close();
        }

        self::assertSame([], $this->bank->fetchedDays);
        self::assertTrue($this->logs->hasWarningThatContains('another import of the account is running'));
    }

    private function handle(?SyncBankConnectionMessage $message = null): void
    {
        $this->handler(new BankProviderRegistry([$this->bank]))(
            $message ?? new SyncBankConnectionMessage($this->companyId, $this->bankConnection->getId()),
        );
    }

    private function handler(BankProviderRegistry $registry): SyncBankConnectionHandler
    {
        return new SyncBankConnectionHandler(
            $this->em->getRepository(BankConnection::class),
            $this->em->getRepository(BankImportCursor::class),
            $registry,
            $this->codec(),
            self::getContainer()->get(CashFacade::class),
            $this->em,
            new Logger('test', [$this->logs]),
        );
    }

    private function codec(): BankCredentialsCodec
    {
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);

        return new BankCredentialsCodec($encryption);
    }

    private function line(string $uuid, BankDirection $direction, string $amount, string $date): BankStatementLineDTO
    {
        return new BankStatementLineDTO($uuid, $direction, $amount, 'RUB', new \DateTimeImmutable($date.' 12:00'), 'Назначение '.$uuid);
    }

    private function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }

    private function countTransactions(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT count(*) FROM cash_transaction WHERE company_id = :companyId',
            ['companyId' => $this->companyId],
        );
    }

    private function cursorDate(): ?string
    {
        $date = $this->em->getConnection()->fetchOne(
            'SELECT last_imported_date FROM bank_sync_import_cursor WHERE company_id = :companyId',
            ['companyId' => $this->companyId],
        );

        return false === $date ? null : (string) $date;
    }

    private function closingBalance(string $date): ?string
    {
        $balance = $this->em->getConnection()->fetchOne(
            'SELECT closing_balance FROM money_account_daily_balance WHERE money_account_id = :accountId AND date = :date',
            ['accountId' => $this->account->getId(), 'date' => $date],
        );

        return false === $balance ? null : (string) $balance;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function lastImportLog(): array|false
    {
        return $this->em->getConnection()->fetchAssociative(
            'SELECT source, finished_at, created_count, skipped_duplicates, errors_count, file_name
             FROM import_log WHERE company_id = :companyId ORDER BY started_at DESC LIMIT 1',
            ['companyId' => $this->companyId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireLastImportLog(): array
    {
        $log = $this->lastImportLog();
        self::assertIsArray($log);

        return $log;
    }

    private function assertNoSensitiveDataInLogs(): void
    {
        foreach ($this->logs->getRecords() as $record) {
            $serialized = json_encode([$record->message, $record->context], \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TOKEN, $serialized);
            self::assertStringNotContainsString(self::ACCOUNT_NUMBER, $serialized);
            self::assertStringNotContainsString('Назначение', $serialized);
        }
    }
}

/**
 * Банк для теста: выписка по дням, сбои по дням или на списке счетов.
 */
final class FakeBankProvider implements BankProviderInterface
{
    /** @var array<string, list<BankStatementLineDTO>> */
    public array $days = [];
    /** @var array<string, \Throwable> */
    public array $failures = [];
    public ?\Throwable $accountsFailure = null;
    /** @var list<string> */
    public array $fetchedDays = [];
    public bool $accountsListed = false;
    public ?string $lastToken = null;

    /**
     * @param list<BankAccountDTO> $accounts
     */
    public function __construct(private readonly array $accounts)
    {
    }

    public function code(): BankCode
    {
        return BankCode::ALFA;
    }

    public function listAccounts(BankAccess $access): array
    {
        $this->accountsListed = true;
        $this->lastToken = $access->token;
        if (null !== $this->accountsFailure) {
            throw $this->accountsFailure;
        }

        return $this->accounts;
    }

    public function fetchStatementDay(BankAccess $access, string $accountNumber, \DateTimeImmutable $date): BankStatementDayDTO
    {
        $key = $date->format('Y-m-d');
        $this->fetchedDays[] = $key;
        if (isset($this->failures[$key])) {
            throw $this->failures[$key];
        }

        return new BankStatementDayDTO($date, $this->days[$key] ?? []);
    }
}
