<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\MoySklad\Application\Action\ManageMoySkladConnectionAction;
use App\MoySklad\Application\Command\ManageConnectionCommand;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Exception\ConnectionOperationException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ManageConnectionActionTest extends WebTestCaseBase
{
    private const COMPANY = '11111111-1111-1111-1111-111111111111';
    private const ACCOUNT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testCreateEncryptsTokenAndBindsAccount(): void
    {
        $this->resetDb();
        $connection = ($this->action())(new ManageConnectionCommand(self::COMPANY, 'actor', 'create', name: 'Склад', token: 'new-secret'));
        self::assertNotNull($connection);
        self::assertSame(self::ACCOUNT, $connection->getAccountId());
        self::assertNull($connection->getAccessToken());
        self::assertSame('new-secret', static::getContainer()->get(ConnectionTokenCodec::class)->accessTokenFor($connection));
        self::assertSame('connected', $connection->getCheckStatus()->value);
    }

    public function testRejectedReplacementPreservesTokenAndStatus(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $connection->bindAccount(self::ACCOUNT);
        $this->em()->flush();
        try {
            ($this->action(401))(new ManageConnectionCommand(self::COMPANY, 'actor', 'replace-token', $connection->getId(), token: 'bad', version: $connection->getVersion()));
            self::fail('Rejected token must fail');
        } catch (ConnectionOperationException) {
            $this->em()->refresh($connection);
            self::assertSame('old-secret', $connection->getAccessToken());
            self::assertSame('unverified', $connection->getCheckStatus()->value);
        }
    }

    public function testDifferentAccountReplacementIsRejected(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $connection->bindAccount('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
        $this->em()->flush();
        $this->expectException(ConnectionOperationException::class);
        ($this->action())(new ManageConnectionCommand(self::COMPANY, 'actor', 'replace-token', $connection->getId(), token: 'new', version: $connection->getVersion()));
    }

    public function testCannotDeleteActiveConnection(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $this->expectException(ConnectionOperationException::class);
        ($this->action())(new ManageConnectionCommand(self::COMPANY, 'actor', 'delete', $connection->getId(), version: $connection->getVersion()));
    }

    public function testForeignCompanyCannotCheckConnection(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $this->expectException(ConnectionOperationException::class);
        ($this->action())(new ManageConnectionCommand('22222222-2222-2222-2222-222222222222', 'actor', 'check', $connection->getId(), version: $connection->getVersion()));
    }

    public function testDuplicateAccountIsRejectedAcrossCompanies(): void
    {
        $this->resetDb();
        $existing = $this->existing();
        $existing->bindAccount(self::ACCOUNT);
        $existing->setIsActive(false);
        $this->em()->flush();
        $this->expectException(ConnectionOperationException::class);
        ($this->action())(new ManageConnectionCommand('22222222-2222-2222-2222-222222222222', 'actor', 'create', name: 'Другой склад', token: 'new'));
    }

    public function testCheckRecordsFailureButKeepsLastSuccess(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $connection->bindAccount(self::ACCOUNT);
        $connection->recordCheck(\App\MoySklad\Enum\ConnectionCheckStatus::CONNECTED, new \DateTimeImmutable('2026-09-01'));
        $this->em()->flush();
        try {
            ($this->action(503))(new ManageConnectionCommand(self::COMPANY, 'actor', 'check', $connection->getId(), version: $connection->getVersion()));
            self::fail('Unavailable API must be reported');
        } catch (ConnectionOperationException $e) {
            self::assertSame('unavailable', $e->reason);
            $this->em()->refresh($connection);
            self::assertSame('unavailable', $connection->getCheckStatus()->value);
            self::assertSame('2026-09-01', $connection->getLastSuccessfulCheckAt()?->format('Y-m-d'));
            self::assertNotNull($connection->getLastCheckedAt());
        }
    }

    public function testReplacementDoesNotEnableDisabledConnection(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $connection->bindAccount(self::ACCOUNT);
        $connection->setIsActive(false);
        $this->em()->flush();
        ($this->action())(new ManageConnectionCommand(self::COMPANY, 'actor', 'replace-token', $connection->getId(), token: 'replacement', version: $connection->getVersion()));
        $this->em()->refresh($connection);
        self::assertFalse($connection->isActive());
        self::assertSame('replacement', static::getContainer()->get(ConnectionTokenCodec::class)->accessTokenFor($connection));
    }

    public function testStaleHttpResponseCannotUndoConcurrentDisable(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $http = new MockHttpClient(function () use ($connection): MockResponse {
            self::assertFalse($this->em()->getConnection()->isTransactionActive(), 'HTTP must run outside a DB transaction');
            $this->em()->getConnection()->executeStatement('UPDATE moysklad_connections SET is_active = false, version = version + 1 WHERE id = ? AND company_id = ?', [$connection->getId(), self::COMPANY]);

            return new MockResponse(json_encode(['accountId' => self::ACCOUNT], \JSON_THROW_ON_ERROR));
        });
        try {
            ($this->action(http: $http))(new ManageConnectionCommand(self::COMPANY, 'actor', 'enable', $connection->getId(), version: $connection->getVersion()));
            self::fail('Stale HTTP response must not apply');
        } catch (ConnectionOperationException $e) {
            self::assertSame('stale_connection', $e->reason);
            $this->em()->refresh($connection);
            self::assertFalse($connection->isActive());
            self::assertNull($connection->getAccountId());
            self::assertNull($connection->getLastCheckedAt());
        }
    }

    public function testCheckCooldownPreventsSecondRequest(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $action = $this->action();
        $action(new ManageConnectionCommand(self::COMPANY, 'actor', 'check', $connection->getId(), version: $connection->getVersion()));
        try {
            $action(new ManageConnectionCommand(self::COMPANY, 'actor', 'check', $connection->getId(), version: $connection->getVersion()));
            self::fail('Repeated check must be rate limited');
        } catch (ConnectionOperationException $e) {
            self::assertSame('rate_limited', $e->reason);
        }
    }

    public function testConcurrentEnableBetweenLookupAndDeletePreservesConnection(): void
    {
        $this->resetDb();
        $connection = $this->existing();
        $connection->setIsActive(false);
        $this->em()->flush();
        $em = $this->em();
        $proxy = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $proxy->method('wrapInTransaction')->willReturnCallback(function (callable $callback) use ($em, $connection): mixed {
            // Another request commits after the initial read, before DELETE's row lock.
            $em->getConnection()->executeStatement('UPDATE moysklad_connections SET is_active = true, version = version + 1 WHERE id = ? AND company_id = ?', [$connection->getId(), self::COMPANY]);

            return $em->wrapInTransaction($callback);
        });
        $proxy->method('refresh')->willReturnCallback(fn ($entity, $lock = null) => $em->refresh($entity, $lock));
        $proxy->method('remove')->willReturnCallback(fn ($entity) => $em->remove($entity));
        $proxy->method('flush')->willReturnCallback(fn () => $em->flush());
        try {
            ($this->action(em: $proxy))(new ManageConnectionCommand(self::COMPANY, 'actor', 'delete', $connection->getId(), version: $connection->getVersion()));
            self::fail('Concurrent enable must prevent deletion');
        } catch (ConnectionOperationException $e) {
            self::assertSame('stale_connection', $e->reason);
            self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM moysklad_connections WHERE id = ? AND company_id = ? AND is_active = true', [$connection->getId(), self::COMPANY]));
        }
    }

    private function existing(): MoySkladConnection
    {
        $connection = new MoySkladConnection(Uuid::uuid7()->toString(), self::COMPANY, 'Склад', 'https://api.moysklad.ru/api/remap/1.2');
        $connection->setAccessToken('old-secret');
        $this->em()->persist($connection);
        $this->em()->flush();

        return $connection;
    }

    private function action(int $status = 200, ?MockHttpClient $http = null, ?\Doctrine\ORM\EntityManagerInterface $em = null): ManageMoySkladConnectionAction
    {
        return new ManageMoySkladConnectionAction(
            static::getContainer()->get(MoySkladConnectionWriteRepository::class),
            $em ?? $this->em(),
            new MoySkladClient($http ?? new MockHttpClient(new MockResponse(json_encode(['accountId' => self::ACCOUNT], \JSON_THROW_ON_ERROR), ['http_code' => $status])), 'https://api.moysklad.ru/api/remap/1.2'),
            static::getContainer()->get(ConnectionTokenCodec::class),
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '10 seconds'], new InMemoryStorage()),
            new NullLogger(),
        );
    }
}
