<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Application\OAuthTokenRefresher;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Entity\BankImportCursor;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Infrastructure\Bank\BankProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Infrastructure\Security\EncryptedBankCredentials;
use App\BankSync\Message\SyncBankConnectionMessage;
use App\BankSync\MessageHandler\SyncBankConnectionHandler;
use App\Cash\Facade\CashFacade;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\BankSync\FakeBankProvider;
use App\Tests\Support\BankSync\StubBankAuthorizationProvider;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\NativeClock;

/**
 * Два воркера и один refresh-токен на реальных commit: блокировку токенов держит другая
 * сессия PostgreSQL («воркер A»), handler («воркер B») ждёт её.
 */
final class ConcurrentTokenRefreshTest extends PostgresResetTestCase
{
    private string $companyId;
    private BankConnection $bankConnection;
    private Connection $workerA;

    protected function setUp(): void
    {
        parent::setUp();
        StubBankAuthorizationProvider::reset();

        $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-refresh-'.$this->companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $credentials = $this->codec()->encryptOAuthTokens(new OAuthTokens('expired-access', 'single-use-refresh', new \DateTimeImmutable('-1 minute')));
        $this->bankConnection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($this->companyId)
            ->withCredentials($credentials)
            ->build();
        foreach ([$owner, $company, $this->bankConnection] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $this->workerA = DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    protected function tearDown(): void
    {
        $this->workerA->close();

        parent::tearDown();
    }

    public function testSecondWorkerWaitsAndUsesTokensRefreshedByTheFirst(): void
    {
        self::assertTrue((new BankConnectionTokenLock($this->workerA))->acquire($this->bankConnection->getId(), 1));
        $bank = new FakeBankProvider([new BankAccountDTO('40702810900000000077')]);

        // Пока воркер B ждёт, воркер A обновляет токены у банка, коммитит и отпускает блокировку.
        $workerAFinishes = function (): void {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            $fresh = $this->codec()->encryptOAuthTokens(new OAuthTokens('access-from-worker-a', 'refresh-from-worker-a', new \DateTimeImmutable('+1 hour')));
            $this->workerA->executeStatement(
                'UPDATE bank_sync_connection SET credentials_encrypted = :c, credentials_key_version = :v WHERE id = :id',
                ['c' => $fresh->storageJson, 'v' => $fresh->keyVersion, 'id' => $this->bankConnection->getId()],
            );
            (new BankConnectionTokenLock($this->workerA))->release($this->bankConnection->getId());
        };

        $this->handler($bank, $workerAFinishes)(new SyncBankConnectionMessage($this->companyId, $this->bankConnection->getId()));

        self::assertSame(0, StubBankAuthorizationProvider::$refreshCalls, 'один refresh-запрос на два воркера');
        self::assertSame('access-from-worker-a', $bank->lastToken);
    }

    public function testLockHeldTooLongIsRetriedWithoutRefresh(): void
    {
        self::assertTrue((new BankConnectionTokenLock($this->workerA))->acquire($this->bankConnection->getId(), 1));
        $bank = new FakeBankProvider([]);

        try {
            $this->handler($bank, static function (): void {
            })(new SyncBankConnectionMessage($this->companyId, $this->bankConnection->getId()));
            self::fail('busy connection must be retried');
        } catch (BankConnectionBusyException) {
        }

        self::assertSame(0, StubBankAuthorizationProvider::$refreshCalls);
        self::assertFalse($bank->accountsListed);
    }

    public function testRefreshedTokensAreCommittedBeforeBankRequest(): void
    {
        $bank = new FakeBankProvider([]);

        $this->handler($bank, static function (): void {
        })(new SyncBankConnectionMessage($this->companyId, $this->bankConnection->getId()));

        self::assertSame(1, StubBankAuthorizationProvider::$refreshCalls);
        // Видно из другой сессии — значит, закоммичено.
        $stored = (string) $this->workerA->fetchOne('SELECT credentials_encrypted FROM bank_sync_connection WHERE id = :id', ['id' => $this->bankConnection->getId()]);
        $reloaded = BankConnectionBuilder::aConnection()->withCredentials(new EncryptedBankCredentials($stored, 'v1'))->build();
        self::assertSame('refreshed-refresh-1', $this->codec()->oauthTokens($reloaded)?->refreshToken);
    }

    /**
     * @param \Closure(int): void $sleep
     */
    private function handler(FakeBankProvider $bank, \Closure $sleep): SyncBankConnectionHandler
    {
        return new SyncBankConnectionHandler(
            $this->em->getRepository(BankConnection::class),
            $this->em->getRepository(BankImportCursor::class),
            new BankProviderRegistry([$bank]),
            $this->codec(),
            new OAuthTokenRefresher($this->codec(), new BankAuthorizationProviderRegistry([new StubBankAuthorizationProvider()]), new NativeClock()),
            new BankConnectionTokenLock($this->em->getConnection(), $sleep),
            self::getContainer()->get(CashFacade::class),
            $this->em,
            new Logger('test', [new TestHandler()]),
        );
    }

    private function codec(): BankCredentialsCodec
    {
        /** @var FieldEncryptionServiceInterface $encryption */
        $encryption = self::getContainer()->get(FieldEncryptionServiceInterface::class);

        return new BankCredentialsCodec($encryption);
    }
}
