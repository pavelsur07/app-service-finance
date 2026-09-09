<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\MessageHandler;

use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Message\RunSyncChunkMessage;
use App\Ingestion\MessageHandler\RunSyncChunkHandler;
use App\Marketplace\Application\RecordConnectionAuthResultAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionAuthStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Integration\Ingestion\Fixtures\FakeConnector;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Отказ аутентификации доезжает от обработчика до подключения.
 *
 * До этого связи не было вовсе: обработчик помечал падающее задание причиной
 * `auth` и уходил, а подключение оставалось внешне здоровым, из-за чего крон
 * бесконечно ставил задания по мёртвому ключу.
 */
final class RunSyncChunkHandlerAuthStateTest extends IntegrationTestCase
{
    private const THRESHOLD = RecordConnectionAuthResultAction::AUTH_FAILURE_THRESHOLD;

    public function testConsecutiveAuthFailuresBreakTheConnection(): void
    {
        $companyId = '11111111-1111-1111-1111-0c0000000001';
        $connectionId = $this->seedConnection($companyId, 'handler-auth@example.test');

        for ($i = 1; $i <= self::THRESHOLD; ++$i) {
            $this->runJobFailingWithAuthError($companyId, $connectionId);

            $connection = $this->connection($connectionId);
            self::assertSame($i, $connection->getAuthFailureCount());
            self::assertSame(
                $i >= self::THRESHOLD
                    ? MarketplaceConnectionAuthStatus::FAILED
                    : MarketplaceConnectionAuthStatus::OK,
                $connection->getAuthStatus(),
            );
        }

        self::assertNotNull($this->connection($connectionId)->getAuthFailedAt());
    }

    /**
     * `connectionRef` контрактом задания гарантирован лишь непустым, и в
     * репозитории есть задания со ссылкой вроде `connection-1`. Для них
     * состояние обновлять не на чем, и это не ошибка: исходное исключение
     * обязано дойти до Messenger нетронутым.
     */
    public function testNonUuidConnectionRefDoesNotReplaceTheOriginalFailure(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->persistJob($companyId, 'connection-1');

        $this->fakeConnector()->failNextPullWith(new ConnectorAuthException('Fake connector rejected the key.'));

        $handler = $this->handler();

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Ingestion connector authentication failed.');

        $handler(new RunSyncChunkMessage($companyId, $job->getId()));
    }

    /**
     * Путь успеха проходит на КАЖДОМ завершённом чанке. Если бы задание без
     * ссылки на подключение писало отсюда `error`, канал алертов получал бы
     * запись на каждой удачной синхронизации — ложный алерт, обесценивающий
     * канал целиком.
     */
    public function testSuccessfulChunkWithNonUuidConnectionRefLogsNoError(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $job = $this->persistJob($companyId, 'connection-1');

        $this->fakeConnector()->enqueuePullResult(
            externalId: 'fake-page-1',
            nextCursorValue: null,
            hasMore: false,
        );

        /** @var TestHandler $logHandler */
        $logHandler = self::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        ($this->handler())(new RunSyncChunkMessage($companyId, $job->getId()));

        self::assertFalse(
            $logHandler->hasRecords(Level::Error),
            'Успешный чанк не имеет права писать error: путь успеха проходит на каждом чанке',
        );
    }

    /**
     * Успешный чанк обрывает серию отказов.
     *
     * Без этого счётчик копился бы через удачные синхронизации и рано или
     * поздно отключил бы исправное подключение: правило «три отказа ПОДРЯД»
     * держится именно на этом вызове.
     */
    public function testSuccessfulChunkClearsAccumulatedFailures(): void
    {
        $companyId = '11111111-1111-1111-1111-0c0000000002';
        $connectionId = $this->seedConnection($companyId, 'handler-recover@example.test');

        $this->runJobFailingWithAuthError($companyId, $connectionId);
        $this->runJobFailingWithAuthError($companyId, $connectionId);
        self::assertSame(2, $this->connection($connectionId)->getAuthFailureCount());

        $job = $this->persistJob($companyId, $connectionId);
        $this->fakeConnector()->enqueuePullResult(
            externalId: 'fake-page-1',
            nextCursorValue: null,
            hasMore: false,
        );

        ($this->handler())(new RunSyncChunkMessage($companyId, $job->getId()));

        $connection = $this->connection($connectionId);
        self::assertSame(0, $connection->getAuthFailureCount());
        self::assertSame(MarketplaceConnectionAuthStatus::OK, $connection->getAuthStatus());
        self::assertNull($connection->getAuthFailedAt());
    }

    private function runJobFailingWithAuthError(string $companyId, string $connectionId): void
    {
        $job = $this->persistJob($companyId, $connectionId);
        $this->fakeConnector()->failNextPullWith(new ConnectorAuthException('Fake connector rejected the key.'));

        try {
            ($this->handler())(new RunSyncChunkMessage($companyId, $job->getId()));
            self::fail('Auth failure must surface as an unrecoverable message handling exception.');
        } catch (UnrecoverableMessageHandlingException) {
            // Ожидаемо: ключ мёртв, повтор его не вылечит.
        }
    }

    private function handler(): RunSyncChunkHandler
    {
        /** @var RunSyncChunkHandler $handler */
        $handler = self::getContainer()->get(RunSyncChunkHandler::class);

        return $handler;
    }

    private function fakeConnector(): FakeConnector
    {
        /** @var FakeConnector $connector */
        $connector = self::getContainer()->get(FakeConnector::class);
        $connector->reset();

        return $connector;
    }

    private function persistJob(string $companyId, string $connectionRef): SyncJob
    {
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::WILDBERRIES,
            resourceType: FakeConnector::RESOURCE_TYPE,
            kind: SyncJobKind::BACKFILL,
            windowFrom: new \DateTimeImmutable('2026-06-18'),
            windowTo: new \DateTimeImmutable('2026-06-18'),
            shopRef: $connectionRef,
        );

        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function connection(string $connectionId): MarketplaceConnection
    {
        // Состояние пишется мимо UnitOfWork атомарным оператором.
        $this->em->clear();
        $connection = $this->em->find(MarketplaceConnection::class, $connectionId);
        self::assertInstanceOf(MarketplaceConnection::class, $connection);

        return $connection;
    }

    private function seedConnection(string $companyId, string $email): string
    {
        $connectionId = Uuid::uuid7()->toString();
        $owner = UserBuilder::aUser()->withIndex(1)->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();

        $connection = new MarketplaceConnection(
            $connectionId,
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('api-key');

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        return $connectionId;
    }
}
