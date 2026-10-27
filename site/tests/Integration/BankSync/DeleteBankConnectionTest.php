<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\BankAccountCatalog;
use App\BankSync\Application\DeleteBankConnectionAction;
use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Infrastructure\Security\EncryptedBankCredentials;
use App\BankSync\Repository\BankAccountRepository;
use App\BankSync\Repository\BankConnectionRepository;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Builders\BankSync\BankAccountBuilder;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\BankSync\StubBankAuthorizationProvider;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

/**
 * Удаление подключения отзывает refresh в банке до удаления, под блокировкой токенов;
 * сбой отзыва удалению не мешает.
 */
final class DeleteBankConnectionTest extends IntegrationTestCase
{
    private string $companyId;
    private Company $company;
    private TestHandler $logs;
    private Connection $worker;

    protected function setUp(): void
    {
        parent::setUp();
        StubBankAuthorizationProvider::reset();
        $this->companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-delete-'.$this->companyId.'@example.test')->build();
        $this->em->persist($owner);
        $this->company = CompanyBuilder::aCompany()->withId($this->companyId)->withOwner($owner)->build();
        $this->em->persist($this->company);
        $this->em->flush();
        $this->logs = new TestHandler();
        $this->worker = DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    protected function tearDown(): void
    {
        $this->worker->close();
        StubBankAuthorizationProvider::reset();

        parent::tearDown();
    }

    public function testOAuthConnectionIsRevokedInBankBeforeDeleteUnderTokenLock(): void
    {
        $connection = $this->persistConnection($this->codec()->encryptOAuthTokens(new OAuthTokens('access', 'refresh-to-revoke', new \DateTimeImmutable('+1 hour'))));
        $duringRevoke = null;
        StubBankAuthorizationProvider::$duringRevoke = function () use ($connection, &$duringRevoke): void {
            $workerLock = new BankConnectionTokenLock($this->worker);
            $locked = !$workerLock->acquire($connection->getId(), 0);
            if (!$locked) {
                $workerLock->release($connection->getId());
            }
            $duringRevoke = ['locked' => $locked, 'row_exists' => false !== $this->row($connection->getId())];
        };

        $this->action()($this->companyId, $connection->getId());

        self::assertSame(['refresh-to-revoke'], StubBankAuthorizationProvider::$revoked);
        self::assertSame(['locked' => true, 'row_exists' => true], $duringRevoke, 'отзыв — до удаления и под блокировкой токенов');
        self::assertFalse($this->row($connection->getId()));
        self::assertTrue($this->logs->hasInfoThatContains('token revoked in the bank'));
        $this->assertNoTokensLogged();
    }

    public function testRevocationFailureDoesNotPreventDelete(): void
    {
        $connection = $this->persistConnection($this->codec()->encryptOAuthTokens(new OAuthTokens('access', 'refresh-to-revoke', new \DateTimeImmutable('+1 hour'))));
        StubBankAuthorizationProvider::$revokeFailure = new BankApiUnavailableException('alfa', 503);

        $this->action()($this->companyId, $connection->getId());

        self::assertFalse($this->row($connection->getId()));
        self::assertTrue($this->logs->hasWarningThatContains('token revocation failed, connection deleted anyway'));
        self::assertFalse($this->logs->hasErrorRecords());
        $this->assertNoTokensLogged();
    }

    public function testUnreadableCredentialsDoNotPreventDelete(): void
    {
        $connection = $this->persistConnection(new EncryptedBankCredentials('{"v":"missing-key","n":"AA==","c":"AA=="}', 'missing-key'));

        $this->action()($this->companyId, $connection->getId());

        self::assertFalse($this->row($connection->getId()));
        self::assertSame([], StubBankAuthorizationProvider::$revoked);
        self::assertTrue($this->logs->hasWarningThatContains('credentials are unreadable'));
    }

    public function testStaticTokenIsDeletedWithoutRevocation(): void
    {
        $connection = $this->persistConnection($this->codec()->encryptStaticToken('static-token'));

        $this->action()($this->companyId, $connection->getId());

        self::assertFalse($this->row($connection->getId()));
        self::assertSame([], StubBankAuthorizationProvider::$revoked);
    }

    public function testBusyConnectionIsNotDeletedNorRevoked(): void
    {
        $connection = $this->persistConnection($this->codec()->encryptOAuthTokens(new OAuthTokens('access', 'refresh', new \DateTimeImmutable('+1 hour'))));
        self::assertTrue((new BankConnectionTokenLock($this->worker))->acquire($connection->getId(), 0));

        try {
            $this->action(static function (int $microseconds): void {
            })($this->companyId, $connection->getId());
            self::fail('busy connection must be reported');
        } catch (BankConnectionBusyException) {
        }

        self::assertNotFalse($this->row($connection->getId()));
        self::assertSame([], StubBankAuthorizationProvider::$revoked);
    }

    public function testLinkedMoneyAccountLosesBankApiSourceAndBankAccountsAreDeleted(): void
    {
        $connection = $this->persistConnection($this->codec()->encryptStaticToken('static-token'));
        $moneyAccount = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($this->company)->withCurrency('RUB')->build();
        $moneyAccount->markBankApiSource('alfa');
        $this->em->persist($moneyAccount);
        $this->em->persist(BankAccountBuilder::anAccount()
            ->withId(Uuid::uuid7()->toString())
            ->forConnection($this->companyId, $connection->getId())
            ->linkedTo((string) $moneyAccount->getId())
            ->build());
        $this->em->flush();

        $this->action()($this->companyId, $connection->getId());

        self::assertNull($this->em->getConnection()->fetchOne('SELECT bank_api_source FROM money_account WHERE id = :id', ['id' => (string) $moneyAccount->getId()]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM bank_sync_account WHERE connection_id = :id', ['id' => $connection->getId()]));
    }

    public function testForeignConnectionIsNotFound(): void
    {
        $connection = $this->persistConnection($this->codec()->encryptStaticToken('static-token'));

        $this->expectException(BankConnectionNotFoundException::class);

        $this->action()(Uuid::uuid4()->toString(), $connection->getId());
    }

    /**
     * @param (\Closure(int): void)|null $sleep
     */
    private function action(?\Closure $sleep = null): DeleteBankConnectionAction
    {
        return new DeleteBankConnectionAction(
            self::getContainer()->get(BankConnectionRepository::class),
            self::getContainer()->get(BankAccountRepository::class),
            new BankAccountCatalog(self::getContainer()->get(BankAccountRepository::class), $this->em),
            self::getContainer()->get(CashFacade::class),
            $this->codec(),
            self::getContainer()->get(BankAuthorizationProviderRegistry::class),
            new BankConnectionTokenLock($this->em->getConnection(), $sleep),
            $this->em,
            new Logger('test', [$this->logs]),
        );
    }

    private function persistConnection(EncryptedBankCredentials $credentials): BankConnection
    {
        $connection = BankConnectionBuilder::aConnection()
            ->withId(Uuid::uuid7()->toString())
            ->withCompanyId($this->companyId)
            ->withCredentials($credentials)
            ->build();
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function row(string $id): array|false
    {
        return $this->em->getConnection()->fetchAssociative('SELECT id FROM bank_sync_connection WHERE id = :id', ['id' => $id]);
    }

    private function assertNoTokensLogged(): void
    {
        $logged = json_encode(array_map(static fn ($record): array => [$record->message, $record->context], $this->logs->getRecords()), \JSON_THROW_ON_ERROR);
        foreach (['refresh-to-revoke', 'access'] as $secret) {
            self::assertStringNotContainsString('"'.$secret.'"', $logged);
        }
    }

    private function codec(): BankCredentialsCodec
    {
        return self::getContainer()->get(BankCredentialsCodec::class);
    }
}
