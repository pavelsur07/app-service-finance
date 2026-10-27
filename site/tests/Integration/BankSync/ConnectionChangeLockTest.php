<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\CompleteBankAuthorizationAction;
use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Application\SaveBankConnectionAction;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Domain\OAuth\PendingAuthorization;
use App\BankSync\Domain\OAuth\Pkce;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Exception\BankAuthorizationStateInvalidException;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankStaticTokenNotSupportedException;
use App\BankSync\Infrastructure\OAuth\AuthorizationSessionStore;
use App\BankSync\Infrastructure\OAuth\AuthorizationStateClaims;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\BankSync\StubBankAuthorizationProvider;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Callback входа и сохранение формы меняют учётные данные подключения только под
 * блокировкой токенов — той же, под которой воркер обновляет OAuth-токены. Блокировку
 * держит другая сессия PostgreSQL («воркер»).
 */
final class ConnectionChangeLockTest extends IntegrationTestCase
{
    private string $companyId;
    private BankConnection $bankConnection;
    private Connection $worker;
    private AuthorizationSessionStore $sessionStore;

    protected function setUp(): void
    {
        parent::setUp();
        StubBankAuthorizationProvider::reset();

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        $this->sessionStore = self::getContainer()->get(AuthorizationSessionStore::class);

        $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-lock-'.$this->companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $this->bankConnection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($this->companyId)
            ->withCredentials($this->codec()->encryptOAuthTokens(new OAuthTokens('old-access', 'old-refresh', new \DateTimeImmutable('+1 hour'))))
            ->build();
        foreach ([$owner, $company, $this->bankConnection] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $this->worker = DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    protected function tearDown(): void
    {
        $this->worker->close();
        StubBankAuthorizationProvider::reset();

        parent::tearDown();
    }

    public function testReconnectExchangesCodeWhileHoldingTokenLock(): void
    {
        $lockedDuringExchange = null;
        StubBankAuthorizationProvider::$duringExchange = function () use (&$lockedDuringExchange): void {
            $workerLock = new BankConnectionTokenLock($this->worker);
            $lockedDuringExchange = !$workerLock->acquire($this->bankConnection->getId(), 0);
            if (!$lockedDuringExchange) {
                $workerLock->release($this->bankConnection->getId());
            }
        };

        $this->completeReconnect(self::getContainer()->get(CompleteBankAuthorizationAction::class));

        self::assertTrue($lockedDuringExchange, 'параллельный вход ждёт, пока этот не сохранит свою пару токенов');
        self::assertSame(StubBankAuthorizationProvider::ACCESS_TOKEN, $this->storedTokens()->accessToken);
        self::assertTrue((new BankConnectionTokenLock($this->worker))->acquire($this->bankConnection->getId(), 0), 'блокировка отпущена');
    }

    public function testReconnectWhileTokensAreLockedKeepsCodeUnusedAndCredentialsIntact(): void
    {
        self::assertTrue((new BankConnectionTokenLock($this->worker))->acquire($this->bankConnection->getId(), 0));
        $action = new CompleteBankAuthorizationAction(
            $this->sessionStore,
            self::getContainer()->get(AuthorizationStateClaims::class),
            self::getContainer()->get(BankAuthorizationProviderRegistry::class),
            self::getContainer()->get(BankConnectionRepository::class),
            $this->codec(),
            $this->lockWithoutWaiting(),
            $this->em,
            new NativeClock(),
            new NullLogger(),
        );

        try {
            $this->completeReconnect($action);
            self::fail('busy connection must be reported');
        } catch (BankConnectionBusyException) {
        }

        self::assertSame(0, StubBankAuthorizationProvider::$exchangeCalls, 'код не обменян — банк не выдал пару, которую некуда сохранить');
        self::assertSame('old-access', $this->storedTokens()->accessToken);
    }

    public function testSameStateFromTwoConcurrentRequestsExchangesCodeOnce(): void
    {
        // Сессии в Redis без блокировки: второй запрос прочитал запись до того, как первый её удалил.
        $pending = $this->pendingReconnect();
        $action = self::getContainer()->get(CompleteBankAuthorizationAction::class);
        $action($this->companyId, $pending->state, $pending->code, null);
        $this->sessionStore->save($pending->authorization, new \DateTimeImmutable());

        try {
            $action($this->companyId, $pending->state, $pending->code, null);
            self::fail('the second request with the same state must be rejected');
        } catch (BankAuthorizationStateInvalidException $exception) {
            self::assertSame('unknown', $exception->reason);
        }

        self::assertSame(1, StubBankAuthorizationProvider::$exchangeCalls, 'код обменян один раз');
    }

    public function testEnvironmentChangedDuringAuthorizationRejectsCallbackBeforeExchange(): void
    {
        $pending = $this->pendingReconnect();
        $this->connection->executeStatement(
            'UPDATE bank_sync_connection SET base_url = :url WHERE id = :id',
            ['url' => BankConnectionFormData::SANDBOX_URL, 'id' => $this->bankConnection->getId()],
        );

        try {
            self::getContainer()->get(CompleteBankAuthorizationAction::class)($this->companyId, $pending->state, $pending->code, null);
            self::fail('tokens of another environment must not be stored');
        } catch (BankAuthorizationStateInvalidException $exception) {
            self::assertSame('connection_changed', $exception->reason);
        }

        self::assertSame(0, StubBankAuthorizationProvider::$exchangeCalls);
        self::assertSame('old-access', $this->storedTokens()->accessToken);
    }

    public function testSaveWhileTokensAreLockedChangesNothing(): void
    {
        self::assertTrue((new BankConnectionTokenLock($this->worker))->acquire($this->bankConnection->getId(), 0));
        $data = BankConnectionFormData::fromConnection($this->bankConnection);
        $data->isActive = false;

        try {
            $this->saveAction()($this->companyId, $data, $this->bankConnection->getId());
            self::fail('busy connection must be reported');
        } catch (BankConnectionBusyException) {
        }

        self::assertSame('old-access', $this->storedTokens()->accessToken);
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM bank_sync_connection WHERE id = :id', ['id' => $this->bankConnection->getId()]), 'выключение не потеряно молча — клиент видит «занято»');
    }

    public function testStaticTokenOrEnvironmentCannotBeSetOnAlfaConnection(): void
    {
        $withToken = BankConnectionFormData::fromConnection($this->bankConnection);
        $withToken->token = 'manual-token';
        $otherEnvironment = BankConnectionFormData::fromConnection($this->bankConnection);
        $otherEnvironment->baseUrl = BankConnectionFormData::PRODUCTION_URL === $this->bankConnection->getBaseUrl() ? BankConnectionFormData::SANDBOX_URL : BankConnectionFormData::PRODUCTION_URL;

        foreach (['token' => $withToken, 'environment' => $otherEnvironment] as $case => $data) {
            try {
                $this->saveAction()($this->companyId, $data, $this->bankConnection->getId());
                self::fail($case.': Alfa accepts tokens and environment only through sign-in');
            } catch (BankStaticTokenNotSupportedException) {
            }
        }

        self::assertSame('old-access', $this->storedTokens()->accessToken);
    }

    public function testNewAlfaConnectionWithStaticTokenIsRejected(): void
    {
        $data = BankConnectionFormData::fromConnection($this->bankConnection);
        $data->token = 'manual-token';
        $before = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bank_sync_connection');

        $this->expectException(BankStaticTokenNotSupportedException::class);
        try {
            $this->saveAction()($this->companyId, $data);
        } finally {
            self::assertSame($before, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bank_sync_connection'));
        }
    }

    public function testSaveAppliesFormToConnectionStateCommittedMeanwhile(): void
    {
        // Форма открыта на активном подключении; тем временем банк отозвал согласие.
        $data = BankConnectionFormData::fromConnection($this->bankConnection);
        $this->connection->executeStatement(
            "UPDATE bank_sync_connection SET status = 'revoked', status_reason = 'consent_revoked' WHERE id = :id",
            ['id' => $this->bankConnection->getId()],
        );
        $data->isActive = false;

        $this->saveAction()($this->companyId, $data, $this->bankConnection->getId());

        $this->em->clear();
        $connection = $this->em->find(BankConnection::class, $this->bankConnection->getId());
        self::assertNotNull($connection);
        self::assertSame(BankConnectionStatus::REVOKED, $connection->getStatus(), 'флажок не превращает отозванное в выключенное');
        self::assertSame('consent_revoked', $connection->getStatusReason());
    }

    private function completeReconnect(CompleteBankAuthorizationAction $action): void
    {
        $pending = $this->pendingReconnect();

        $action($this->companyId, $pending->state, $pending->code, null);
    }

    /**
     * Начатый вход в существующее подключение: запись в сессии и код, выданный банком.
     *
     * @return object{state: string, code: string, authorization: PendingAuthorization}
     */
    private function pendingReconnect(): object
    {
        $pkce = Pkce::generate();
        $pending = new PendingAuthorization(
            Uuid::uuid4()->toString(),
            $this->companyId,
            BankCode::ALFA,
            $this->bankConnection->getBaseUrl(),
            $this->bankConnection->getId(),
            false,
            $pkce->verifier,
            'http://localhost/bank-connections/oauth/callback',
            new \DateTimeImmutable('+10 minutes'),
            'test-nonce',
        );
        $this->sessionStore->save($pending, new \DateTimeImmutable());

        return (object) ['state' => $pending->state, 'code' => 'ok:'.$pkce->challenge, 'authorization' => $pending];
    }

    private function saveAction(): SaveBankConnectionAction
    {
        return new SaveBankConnectionAction(
            self::getContainer()->get(BankConnectionRepository::class),
            $this->codec(),
            $this->lockWithoutWaiting(),
            $this->em,
        );
    }

    private function lockWithoutWaiting(): BankConnectionTokenLock
    {
        return new BankConnectionTokenLock($this->em->getConnection(), static function (int $microseconds): void {
        });
    }

    private function storedTokens(): OAuthTokens
    {
        $this->em->clear();
        $connection = $this->em->find(BankConnection::class, $this->bankConnection->getId());
        self::assertNotNull($connection);
        $tokens = $this->codec()->oauthTokens($connection);
        self::assertNotNull($tokens);

        return $tokens;
    }

    private function codec(): BankCredentialsCodec
    {
        return self::getContainer()->get(BankCredentialsCodec::class);
    }
}
