<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\DTO\BankStatementLineDTO;
use App\BankSync\Application\OAuthTokenRefresher;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Entity\BankImportCursor;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Enum\BankDirection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\Bank\BankProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Infrastructure\Security\EncryptedBankCredentials;
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
use App\Tests\Support\BankSync\FakeBankProvider;
use App\Tests\Support\BankSync\StubBankAuthorizationProvider;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\NativeClock;
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

        $this->bank = new FakeBankProvider([new BankAccountDTO(self::ACCOUNT_NUMBER, 'RUB'), new BankAccountDTO('40702810900000009999', 'RUB')]);
        $this->logs = new TestHandler();
        StubBankAuthorizationProvider::reset();
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
        // 400 — запрос, которого банк не принимает: ошибка адаптера, ретраи не помогут.
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 400);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle();
    }

    public function testForbiddenMarksNeedsReauthWithoutRetryOrError(): void
    {
        // 403 — сервис не входит в права токена: обновление не поможет, нужен вход с правами.
        $this->useOAuth('+30 minutes');
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 403);

        $this->handle();

        self::assertSame(BankConnectionStatus::NEEDS_REAUTH, $this->reloadConnection()->getStatus());
        self::assertSame('insufficient_scope', $this->reloadConnection()->getStatusReason());
        self::assertSame(0, StubBankAuthorizationProvider::$refreshCalls, 'обновление токена не поможет');
        self::assertFalse($this->logs->hasErrorRecords());
        self::assertTrue($this->logs->hasWarningThatContains('connection needs reauthorization'));
        self::assertNotNull($this->requireLastImportLog()['finished_at'], 'журнал закрыт');

        $this->bank->accountsListed = false;
        $this->handle();
        self::assertFalse($this->bank->accountsListed, 'следующее сообщение пропускает подключение');
    }

    public function testUnconfiguredSignInDuringRefreshIsUnrecoverableWithOneError(): void
    {
        $this->useOAuth('+2 minutes');
        StubBankAuthorizationProvider::$refreshFailure = new BankAuthorizationNotConfiguredException('alfa', 'ALFA_PROD_CLIENT_ID is not set');

        try {
            $this->handle();
            self::fail('misconfigured sign-in must not be retried');
        } catch (UnrecoverableMessageHandlingException) {
        }

        self::assertCount(1, array_filter($this->logs->getRecords(), static fn ($record): bool => $record->level->value >= 400));
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reloadConnection()->getStatus(), 'не needs_reauth: чинить нам, а не клиенту');
    }

    public function testUnconfiguredBankChannelIsUnrecoverableWithOneErrorAndClosedLog(): void
    {
        $this->bank->accountsFailure = new BankChannelNotConfiguredException('alfa', 'ALFA_TLS_CERT_PATH is not set');

        try {
            $this->handle();
            self::fail('misconfigured channel must not be retried');
        } catch (UnrecoverableMessageHandlingException $exception) {
            self::assertInstanceOf(BankChannelNotConfiguredException::class, $exception->getPrevious());
        }

        $errors = array_filter($this->logs->getRecords(), static fn ($record): bool => 'ERROR' === $record->level->getName());
        self::assertCount(1, $errors);
        self::assertStringContainsString('ALFA_TLS_CERT_PATH', (string) array_values($errors)[0]->context['problem']);
        self::assertNotNull($this->requireLastImportLog()['finished_at'], 'журнал импорта закрыт');
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reloadConnection()->getStatus(), 'подключение клиента не виновато');
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
        $this->bankConnection->disable();
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

    public function testExpiringOAuthAccessIsRefreshedBeforeBankRequest(): void
    {
        $this->useOAuth('+2 minutes');

        $this->handle();

        self::assertSame(1, StubBankAuthorizationProvider::$refreshCalls);
        self::assertSame('refreshed-access-1', $this->bank->lastToken);
        $tokens = $this->storedTokens();
        self::assertSame('refreshed-access-1', $tokens->accessToken);
        self::assertSame('refreshed-refresh-1', $tokens->refreshToken, 'новый refresh сохранён');
        self::assertTrue($this->logs->hasInfoThatContains('OAuth tokens refreshed'));
        $this->assertNoSensitiveDataInLogs();
    }

    public function testFreshOAuthAccessIsNotRefreshed(): void
    {
        $this->useOAuth('+30 minutes');

        $this->handle();

        self::assertSame(0, StubBankAuthorizationProvider::$refreshCalls);
        self::assertSame('oauth-access', $this->bank->lastToken);
    }

    public function testRejectedRefreshMarksNeedsReauthOnceWithoutErrors(): void
    {
        $this->useOAuth('-1 minute');
        StubBankAuthorizationProvider::$refreshFailure = new BankAuthorizationRejectedException('alfa', 400, 'invalid_grant');

        $this->handle();
        $this->handle();

        self::assertSame(BankConnectionStatus::NEEDS_REAUTH, $this->reloadConnection()->getStatus());
        self::assertSame('refresh_rejected', $this->reloadConnection()->getStatusReason());
        self::assertFalse($this->bank->accountsListed, 'с отвергнутым токеном к банку не ходим');
        self::assertFalse($this->lastImportLog());
        self::assertSame(1, StubBankAuthorizationProvider::$refreshCalls, 'второй прогон не трогает банк');
        self::assertCount(1, array_filter($this->logs->getRecords(), static fn ($record): bool => str_contains($record->message, 'needs reauthorization')));
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testTemporaryRefreshFailureIsRetriedAndKeepsStatus(): void
    {
        $this->useOAuth('-1 minute');
        StubBankAuthorizationProvider::$refreshFailure = new BankApiUnavailableException('alfa', 503);

        try {
            $this->handle();
            self::fail('temporary failure must reach the transport retry');
        } catch (BankApiUnavailableException) {
        }

        self::assertSame(BankConnectionStatus::ACTIVE, $this->reloadConnection()->getStatus());
        self::assertFalse($this->bank->accountsListed);
    }

    public function testUnauthorizedStaticTokenMarksNeedsReauth(): void
    {
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);

        $this->handle();

        self::assertSame(BankConnectionStatus::NEEDS_REAUTH, $this->reloadConnection()->getStatus());
        self::assertSame('token_rejected', $this->reloadConnection()->getStatusReason());
        self::assertFalse($this->logs->hasErrorRecords());
        self::assertNotNull($this->requireLastImportLog()['finished_at'], 'журнал закрыт');
    }

    public function testUnauthorizedStaticTokenReplacedDuringRunKeepsConnectionActive(): void
    {
        // Пользователь сохранил новый токен, пока банк отвечал 401 на прежний.
        $this->bank->duringAccountsRequest = function (): void {
            $this->replaceStoredCredentials($this->codec()->encryptStaticToken('new-static-token'));
        };
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);

        $this->handle();

        $connection = $this->reloadConnection();
        self::assertSame(BankConnectionStatus::ACTIVE, $connection->getStatus(), '401 на прежний токен не гасит новый');
        self::assertSame('new-static-token', $this->codec()->token($connection));
        self::assertTrue($this->logs->hasInfoThatContains('already replaced'));
        self::assertFalse($this->logs->hasWarningThatContains('needs reauthorization'));
    }

    public function testUnauthorizedOAuthAfterReauthorizationRetriesWithNewTokensWithoutRefresh(): void
    {
        $this->useOAuth('+30 minutes');
        // Клиент заново вошёл в банк, пока шёл запрос с прежним access; прежний refresh отвергнут бы.
        $this->bank->duringAccountsRequest = function (): void {
            $this->replaceStoredCredentials($this->codec()->encryptOAuthTokens(
                new OAuthTokens('reauthorized-access', 'reauthorized-refresh', new \DateTimeImmutable('+1 hour')),
            ));
            $this->bank->duringAccountsRequest = null;
        };
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);
        $this->bank->accountsFailuresLeft = 1;
        StubBankAuthorizationProvider::$refreshFailure = new BankAuthorizationRejectedException('alfa', 400, 'invalid_grant');

        $this->handle();

        self::assertSame(0, StubBankAuthorizationProvider::$refreshCalls, 'новые токены не обновляются');
        self::assertSame('reauthorized-access', $this->bank->lastToken, 'повтор — с токенами нового входа');
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reloadConnection()->getStatus());
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testUnauthorizedOAuthAccessIsRefreshedAndRetriedInTheSameRun(): void
    {
        $this->useOAuth('+30 minutes');
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);
        $this->bank->accountsFailuresLeft = 1;

        $this->handle();

        self::assertSame(1, StubBankAuthorizationProvider::$refreshCalls, 'не истёкший, но отвергнутый access обновлён');
        self::assertSame('refreshed-access-1', $this->bank->lastToken, 'повтор — с обновлённым токеном');
        self::assertSame('refreshed-access-1', $this->storedTokens()->accessToken);
        self::assertSame(BankConnectionStatus::ACTIVE, $this->reloadConnection()->getStatus());
        self::assertNotNull($this->requireLastImportLog()['finished_at']);
        self::assertTrue($this->logs->hasInfoThatContains('Bank sync finished'));
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testRefreshedAccessRejectedAgainMarksNeedsReauthWithoutFurtherRefresh(): void
    {
        $this->useOAuth('+30 minutes');
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);

        $this->handle();

        self::assertSame(1, StubBankAuthorizationProvider::$refreshCalls, 'одно обновление за прогон');
        $connection = $this->reloadConnection();
        self::assertSame(BankConnectionStatus::NEEDS_REAUTH, $connection->getStatus());
        self::assertSame('token_rejected', $connection->getStatusReason());
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testUnauthorizedOAuthWithRejectedRefreshMarksNeedsReauth(): void
    {
        $this->useOAuth('+30 minutes');
        $this->bank->accountsFailure = new BankApiRequestFailedException('alfa', 401);
        StubBankAuthorizationProvider::$refreshFailure = new BankAuthorizationRejectedException('alfa', 400, 'invalid_grant');

        $this->handle();

        self::assertSame(BankConnectionStatus::NEEDS_REAUTH, $this->reloadConnection()->getStatus());
        self::assertFalse($this->logs->hasErrorRecords());
    }

    public function testNeedsReauthConnectionIsSkipped(): void
    {
        $this->bankConnection->markNeedsReauth('refresh_rejected');
        $this->em->flush();

        $this->handle();

        self::assertFalse($this->bank->accountsListed);
        self::assertTrue($this->logs->hasInfoThatContains('connection is not active'));
        self::assertFalse($this->logs->hasWarningRecords());
    }

    private function useOAuth(string $accessExpiresIn): void
    {
        $credentials = $this->codec()->encryptOAuthTokens(new OAuthTokens(
            'oauth-access',
            'oauth-refresh',
            new \DateTimeImmutable($accessExpiresIn),
        ));
        $this->bankConnection->reauthorize($credentials->storageJson, $credentials->keyVersion);
        $this->em->flush();
    }

    /**
     * Запись другого процесса мимо EntityManager handler'а.
     */
    private function replaceStoredCredentials(EncryptedBankCredentials $credentials): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE bank_sync_connection SET credentials_encrypted = :credentials, credentials_key_version = :key_version WHERE id = :id',
            ['credentials' => $credentials->storageJson, 'key_version' => $credentials->keyVersion, 'id' => $this->bankConnection->getId()],
        );
    }

    private function storedTokens(): OAuthTokens
    {
        $tokens = $this->codec()->oauthTokens($this->reloadConnection());
        self::assertNotNull($tokens);

        return $tokens;
    }

    private function reloadConnection(): BankConnection
    {
        $this->em->clear();
        $connection = $this->em->find(BankConnection::class, $this->bankConnection->getId());
        self::assertNotNull($connection);

        return $connection;
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
            new OAuthTokenRefresher($this->codec(), new BankAuthorizationProviderRegistry([new StubBankAuthorizationProvider()]), new NativeClock()),
            new BankConnectionTokenLock($this->em->getConnection()),
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
            foreach (['oauth-access', 'oauth-refresh', 'refreshed-access', 'refreshed-refresh'] as $token) {
                self::assertStringNotContainsString($token, $serialized);
            }
        }
    }
}
