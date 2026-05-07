<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Application\Service\MarketplaceWeekPartitionService;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Message\InitialSyncMessage;
use App\Marketplace\MessageHandler\InitialSyncHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterInterface;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class InitialSyncHandlerTest extends TestCase
{
    private const COMPANY_ID    = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';

    /**
     * Сценарий: первая партия split-недели уже отгружена,
     * следующая партия = вторая половина (2026-03-30..03-31).
     * Handler не должен пересчитывать nextDateTo через 'sunday this week' —
     * это сломало бы границу месяца. Должен взять nextDateTo как есть и
     * посчитать партию-после-следующей с понедельника 2026-04-01.
     *
     * Формат дат в цепочке — 'Y-m-d H:i:s' (как возвращает buildPartitions
     * и как эмитит TriggerInitialSyncHandler).
     */
    public function testNextBatchPreservesMonthBoundarySplit(): void
    {
        [$handler, $captured] = $this->createHandler(new MockClock('2026-04-10 12:00:00'));

        // Текущая партия = 2026-03-23 .. 2026-03-29 (полная неделя Пн-Вс).
        // Следующая партия (split) = 2026-03-30 .. 2026-03-31 — первая половина
        // недели 30.03–05.04, разрезанной по границе месяца.
        $message = new InitialSyncMessage(
            companyId:    self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace:  MarketplaceType::OZON->value,
            dateFrom:     '2026-03-23 00:00:00',
            dateTo:       '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo:   '2026-03-31 23:59:59',
        );

        $handler($message);

        $dispatched = $captured->message;
        self::assertInstanceOf(InitialSyncMessage::class, $dispatched);
        self::assertSame(self::COMPANY_ID, $dispatched->companyId);
        self::assertSame(self::CONNECTION_ID, $dispatched->connectionId);
        self::assertSame(MarketplaceType::OZON->value, $dispatched->marketplace);

        // Текущая партия следующего сообщения = вторая половина split-недели как есть.
        self::assertSame('2026-03-30 00:00:00', $dispatched->dateFrom);
        self::assertSame('2026-03-31 23:59:59', $dispatched->dateTo);

        // Партия-после-следующей = первая партия от 2026-04-01 (понедельник нового месяца)
        // до ближайшего воскресенья 2026-04-05.
        self::assertSame('2026-04-01 00:00:00', $dispatched->nextDateFrom);
        self::assertSame('2026-04-05 23:59:59', $dispatched->nextDateTo);
    }

    /**
     * Сценарий: nextDateTo в будущем относительно вчера (например, сообщение
     * задержалось в очереди или clock сдвинулся). Handler обязан обрезать
     * dateTo до вчерашнего дня и завершить цепочку (nextDateFrom/nextDateTo = null).
     *
     * Первичная синхронизация никогда не загружает данные за сегодня —
     * за них отвечает ежедневный cron на следующий день.
     */
    public function testNextBatchDateToClampedToYesterday(): void
    {
        // Сегодня = 2026-04-03; вчера = 2026-04-02; nextDateTo в сообщении = 2026-04-05 23:59:59 (будущее).
        [$handler, $captured] = $this->createHandler(new MockClock('2026-04-03 12:00:00'));

        $message = new InitialSyncMessage(
            companyId:    self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace:  MarketplaceType::OZON->value,
            dateFrom:     '2026-03-30 00:00:00',
            dateTo:       '2026-03-31 23:59:59',
            nextDateFrom: '2026-04-01 00:00:00',
            nextDateTo:   '2026-04-05 23:59:59',
        );

        $handler($message);

        $dispatched = $captured->message;
        self::assertInstanceOf(InitialSyncMessage::class, $dispatched);

        // dateFrom пробрасывается без изменений.
        self::assertSame('2026-04-01 00:00:00', $dispatched->dateFrom);
        // dateTo должен быть обрезан до $yesterday (clock midnight - 1 day).
        self::assertSame('2026-04-02 00:00:00', $dispatched->dateTo);

        // Цепочка должна завершиться — after-start (2026-04-03) > yesterday → партий нет.
        self::assertNull($dispatched->nextDateFrom);
        self::assertNull($dispatched->nextDateTo);
    }

    public function testLockNotAcquiredSkipsBatch(): void
    {
        [$handler, $captured] = $this->createHandler(new MockClock('2026-04-10 12:00:00'), null, lockAcquired: false);

        $handler(new InitialSyncMessage(
            companyId: self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace: MarketplaceType::OZON->value,
            dateFrom: '2026-03-23 00:00:00',
            dateTo: '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo: '2026-03-31 23:59:59',
        ));

        self::assertNull($captured->message);
    }

    public function testEmptyBatchStillDispatchesNextMessage(): void
    {
        [$handler, $captured, $em] = $this->createHandler(new MockClock('2026-04-10 12:00:00'), emptyRawData: true, expectedFetchCalls: 1);

        $em->expects(self::never())->method('persist');

        $handler(new InitialSyncMessage(
            companyId: self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace: MarketplaceType::OZON->value,
            dateFrom: '2026-03-23 00:00:00',
            dateTo: '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo: '2026-03-31 23:59:59',
        ));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
    }

    public function testFirstRunPersistsRawDocumentAndDispatchesNextMessage(): void
    {
        [$handler, $captured, $em] = $this->createHandler(new MockClock('2026-04-10 12:00:00'), expectedFetchCalls: 1);

        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(MarketplaceRawDocument::class));

        $handler(new InitialSyncMessage(
            companyId: self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace: MarketplaceType::OZON->value,
            dateFrom: '2026-03-23 00:00:00',
            dateTo: '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo: '2026-03-31 23:59:59',
        ));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
    }

    public function testSameBatchIdempotentSkipDispatchesNextMessageWithoutPersist(): void
    {
        $existingRawDoc = new MarketplaceRawDocument(
            '33333333-3333-4333-8333-333333333333',
            CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build(),
            MarketplaceType::OZON,
            'sales_report',
        );
        [$handler, $captured, $em] = $this->createHandler(
            new MockClock('2026-04-10 12:00:00'),
            existingRawDocument: $existingRawDoc,
            expectedFetchCalls: 0,
        );

        $em->expects(self::never())->method('persist');

        $handler(new InitialSyncMessage(
            companyId: self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace: MarketplaceType::OZON->value,
            dateFrom: '2026-03-23 00:00:00',
            dateTo: '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo: '2026-03-31 23:59:59',
        ));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
    }

    public function testUniqueViolationOnFlushIsTreatedAsIdempotentSkipAndDispatchesNextMessage(): void
    {
        $uniqueViolation = $this->createMock(UniqueConstraintViolationException::class);
        [$handler, $captured, $em] = $this->createHandler(
            new MockClock('2026-04-10 12:00:00'),
            flushException: $uniqueViolation,
            entityManagerOpenAfterFlushException: false,
            expectedFetchCalls: 1,
        );

        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(MarketplaceRawDocument::class));

        $handler(new InitialSyncMessage(
            companyId: self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace: MarketplaceType::OZON->value,
            dateFrom: '2026-03-23 00:00:00',
            dateTo: '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo: '2026-03-31 23:59:59',
        ));

        self::assertInstanceOf(InitialSyncMessage::class, $captured->message);
    }

    public function testUniqueViolationOnFinalBatchThrowsRecoverableWhenEntityManagerClosed(): void
    {
        $uniqueViolation = $this->createMock(UniqueConstraintViolationException::class);
        [$handler, $captured] = $this->createHandler(
            new MockClock('2026-04-10 12:00:00'),
            flushException: $uniqueViolation,
            entityManagerOpenAfterFlushException: false,
            expectedFetchCalls: 1,
        );

        $this->expectException(RecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('EntityManager closed after unique violation on final initial sync batch.');

        try {
            $handler(new InitialSyncMessage(
                companyId: self::COMPANY_ID,
                connectionId: self::CONNECTION_ID,
                marketplace: MarketplaceType::OZON->value,
                dateFrom: '2026-03-23 00:00:00',
                dateTo: '2026-03-29 23:59:59',
                nextDateFrom: null,
                nextDateTo: null,
            ));
        } finally {
            self::assertNull($captured->message);
        }
    }

    public function testRateLimitWithRetryAfterDoesNotDispatchNextMessageAndSetsDelay(): void
    {
        [$handler, $captured] = $this->createHandler(
            new MockClock('2026-04-10 12:00:00'),
            new MarketplaceRateLimitException(429, 'rate-limit', '2026-03-23', '2026-03-29', 60),
        );

        try {
            $handler(new InitialSyncMessage(
                companyId: self::COMPANY_ID,
                connectionId: self::CONNECTION_ID,
                marketplace: MarketplaceType::OZON->value,
                dateFrom: '2026-03-23 00:00:00',
                dateTo: '2026-03-29 23:59:59',
                nextDateFrom: '2026-03-30 00:00:00',
                nextDateTo: '2026-03-31 23:59:59',
            ));
            self::fail('Expected RecoverableMessageHandlingException was not thrown.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(60_000, $e->getRetryDelay());
            self::assertNull($captured->message);
        }
    }

    public function testRateLimitWithoutRetryAfterUsesFallbackDelay(): void
    {
        [$handler, $captured] = $this->createHandler(
            new MockClock('2026-04-10 12:00:00'),
            new MarketplaceRateLimitException(429, 'rate-limit', '2026-03-23', '2026-03-29', null),
        );

        try {
            $handler(new InitialSyncMessage(
                companyId: self::COMPANY_ID,
                connectionId: self::CONNECTION_ID,
                marketplace: MarketplaceType::OZON->value,
                dateFrom: '2026-03-23 00:00:00',
                dateTo: '2026-03-29 23:59:59',
                nextDateFrom: '2026-03-30 00:00:00',
                nextDateTo: '2026-03-31 23:59:59',
            ));
            self::fail('Expected RecoverableMessageHandlingException was not thrown.');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertSame(600_000, $e->getRetryDelay());
            self::assertNull($captured->message);
        }
    }

    public function testAuthErrorDoesNotDispatchNextMessage(): void
    {
        [$handler, $captured] = $this->createHandler(
            new MockClock('2026-04-10 12:00:00'),
            new MarketplaceAuthException('auth', 401, 'denied', '2026-03-23', '2026-03-29'),
        );

        $handler(new InitialSyncMessage(
            companyId: self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            marketplace: MarketplaceType::OZON->value,
            dateFrom: '2026-03-23 00:00:00',
            dateTo: '2026-03-29 23:59:59',
            nextDateFrom: '2026-03-30 00:00:00',
            nextDateTo: '2026-03-31 23:59:59',
        ));

        self::assertNull($captured->message);
    }

    /**
     * @return array{0: InitialSyncHandler, 1: \stdClass, 2: EntityManagerInterface, 3: MarketplaceAdapterInterface} captured->message хранит последнее задиспатченное сообщение
     */
    private function createHandler(
        MockClock $clock,
        ?\Throwable $fetchException = null,
        bool $lockAcquired = true,
        bool $emptyRawData = false,
        ?MarketplaceRawDocument $existingRawDocument = null,
        ?int $expectedFetchCalls = null,
        ?\Throwable $flushException = null,
        bool $entityManagerOpenAfterFlushException = true,
    ): array
    {
        $company    = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(
            self::CONNECTION_ID,
            $company,
            MarketplaceType::OZON,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static function (string $class, string $id) use ($company, $connection) {
            if ($class === Company::class && $id === self::COMPANY_ID) {
                return $company;
            }
            if ($class === MarketplaceConnection::class && $id === self::CONNECTION_ID) {
                return $connection;
            }

            return null;
        });

        $rawDocumentRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocumentRepository->method('findExistingInitialSyncDocument')->willReturn($existingRawDocument);
        $em->method('getRepository')->with(MarketplaceRawDocument::class)->willReturn($rawDocumentRepository);
        if ($flushException !== null) {
            $em->method('flush')->willThrowException($flushException);
        }
        $em->method('isOpen')->willReturn($entityManagerOpenAfterFlushException);

        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->method('getApiEndpointName')->willReturn('test/endpoint');
        // Непустой raw → handler сохранит MarketplaceRawDocument и дойдёт до dispatch.
        $fetchRawReportExpectation = $expectedFetchCalls !== null
            ? $adapter->expects(self::exactly($expectedFetchCalls))
            : $adapter->expects(self::any());

        if ($fetchException !== null) {
            $fetchRawReportExpectation->method('fetchRawReport')->willThrowException($fetchException);
        } elseif ($emptyRawData) {
            $fetchRawReportExpectation->method('fetchRawReport')->willReturn([]);
        } else {
            $fetchRawReportExpectation->method('fetchRawReport')->willReturn([['ok' => 1]]);
        }

        $registry = new MarketplaceAdapterRegistry([$adapter]);

        // Контейнер для пойманного сообщения — анонимный объект, чтобы можно было
        // вернуть его по ссылке из фабрики.
        $captured  = new \stdClass();
        $captured->message = null;

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $message) use ($captured): Envelope {
            $captured->message = $message;

            return new Envelope($message);
        });

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn($lockAcquired);
        $lock->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $handler = new InitialSyncHandler(
            $em,
            $registry,
            $lockFactory,
            $messageBus,
            new NullLogger(),
            new MarketplaceWeekPartitionService(),
            $clock,
        );

        return [$handler, $captured, $em, $adapter];
    }
}
