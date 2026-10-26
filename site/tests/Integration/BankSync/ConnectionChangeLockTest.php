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
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Infrastructure\OAuth\AuthorizationSessionStore;
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

    public function testSaveWhileTokensAreLockedChangesNothing(): void
    {
        self::assertTrue((new BankConnectionTokenLock($this->worker))->acquire($this->bankConnection->getId(), 0));
        $data = BankConnectionFormData::fromConnection($this->bankConnection);
        $data->token = 'manual-token';

        try {
            $this->saveAction()($this->companyId, $data, $this->bankConnection->getId());
            self::fail('busy connection must be reported');
        } catch (BankConnectionBusyException) {
        }

        self::assertSame('old-access', $this->storedTokens()->accessToken, 'воркер сохранит свои токены, ручной токен не потерян молча');
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
        );
        $this->sessionStore->save($pending, new \DateTimeImmutable());

        $action($this->companyId, $pending->state, 'ok:'.$pkce->challenge, null);
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
