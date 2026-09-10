<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonAccrualByDayClientInterface;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use App\Marketplace\MessageHandler\SyncOzonAccrualByDayHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncOzonAccrualByDayHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';
    private const DATE = '2026-09-08';
    private const EXISTING_DOC_ID = '33333333-3333-4333-8333-333333333333';

    public function testCreatesDocumentWithByDayFormatAndItsOwnDocumentType(): void
    {
        // document_type отличается от sales_report намеренно: частичный
        // уникальный индекс запрещает два активных документа Ozon sales_report
        // на один день, поэтому легаси и by-day не могут делить тип.
        $persisted = null;
        $em = $this->em($persisted);

        $bus = $this->createMock(MessageBusInterface::class);
        $dispatched = null;
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $handler = $this->handler($em, $this->client([['accrual_id' => 1]]), $bus, []);
        $handler(new SyncOzonAccrualByDayMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertInstanceOf(MarketplaceRawDocument::class, $persisted);
        self::assertSame('accrual_by_day', $persisted->getDocumentType());
        self::assertSame(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value, $persisted->getApiEndpoint());
        self::assertSame([['accrual_id' => 1]], $persisted->getRawData());
        self::assertSame(1, $persisted->getRecordsCount());
        self::assertSame(self::DATE, $persisted->getPeriodFrom()->format('Y-m-d'));
        self::assertSame(self::DATE, $persisted->getPeriodTo()->format('Y-m-d'));

        self::assertInstanceOf(ProcessDayReportMessage::class, $dispatched);
        self::assertSame($persisted->getId(), $dispatched->rawDocumentId);
    }

    public function testEmptyDayIsRecordedAsLoadedInsteadOfLeavingNoTrace(): void
    {
        // «Не загружали» и «загрузили, начислений нет» обязаны различаться:
        // именно неотличимость этих состояний скрыла сбой 09.09.2026.
        $persisted = null;
        $em = $this->em($persisted);

        $handler = $this->handler($em, $this->client([]), $this->createMock(MessageBusInterface::class), []);
        $handler(new SyncOzonAccrualByDayMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertInstanceOf(MarketplaceRawDocument::class, $persisted);
        self::assertSame([], $persisted->getRawData());
        self::assertSame(0, $persisted->getRecordsCount());
    }

    public function testRepeatedRunRefreshesExistingDocumentWithoutCreatingDuplicate(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $existing = new MarketplaceRawDocument(self::EXISTING_DOC_ID, $company, MarketplaceType::OZON, 'accrual_by_day');
        $day = new \DateTimeImmutable(self::DATE);
        $existing->setPeriodFrom($day);
        $existing->setPeriodTo($day);
        $existing->setApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $existing->setRawData([['accrual_id' => 'old']]);
        $existing->setRecordsCount(1);

        $persisted = null;
        $em = $this->em($persisted, $company);

        $handler = $this->handler($em, $this->client([['accrual_id' => 'new']]), $this->createMock(MessageBusInterface::class), [$existing]);
        $handler(new SyncOzonAccrualByDayMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertNull($persisted, 'Повторный прогон не должен создавать второй документ.');
        self::assertSame([['accrual_id' => 'new']], $existing->getRawData());
        self::assertSame(self::EXISTING_DOC_ID, $existing->getId());
    }

    public function testRateLimitIsRetriedByMessenger(): void
    {
        $persisted = null;
        $client = $this->createMock(OzonAccrualByDayClientInterface::class);
        $client->method('fetchDay')->willThrowException(
            new MarketplaceRateLimitException(429, '{"code":8}', self::DATE, self::DATE, 30),
        );

        $handler = $this->handler($this->em($persisted), $client, $this->createMock(MessageBusInterface::class), []);

        $this->expectException(RecoverableMessageHandlingException::class);

        $handler(new SyncOzonAccrualByDayMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));
    }

    public function testUnrecoverableApiErrorGoesToFailedTransportInsteadOfVanishing(): void
    {
        $persisted = null;
        $client = $this->createMock(OzonAccrualByDayClientInterface::class);
        $client->method('fetchDay')->willThrowException(
            new MarketplaceBadRequestException('rejected', 400, '{"code":9, "message":"obsolete method cannot be used"}', self::DATE, self::DATE),
        );

        $handler = $this->handler($this->em($persisted), $client, $this->createMock(MessageBusInterface::class), []);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $handler(new SyncOzonAccrualByDayMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function client(array $rows): OzonAccrualByDayClientInterface
    {
        $client = $this->createMock(OzonAccrualByDayClientInterface::class);
        $client->method('fetchDay')->willReturn($rows);

        return $client;
    }

    private function em(?MarketplaceRawDocument &$persisted, ?Company $company = null): EntityManagerInterface
    {
        $company ??= CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static function (string $class, string $id) use ($company, $connection) {
            if (Company::class === $class && self::COMPANY_ID === $id) {
                return $company;
            }
            if (MarketplaceConnection::class === $class && self::CONNECTION_ID === $id) {
                return $connection;
            }

            return null;
        });
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            if ($entity instanceof MarketplaceRawDocument) {
                $persisted = $entity;
            }
        });

        return $em;
    }

    /**
     * @param list<MarketplaceRawDocument> $existingDocuments
     */
    private function handler(
        EntityManagerInterface $em,
        OzonAccrualByDayClientInterface $client,
        MessageBusInterface $bus,
        array $existingDocuments,
    ): SyncOzonAccrualByDayHandler {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $repository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $repository->method('findActiveExactDayDocuments')->willReturn($existingDocuments);

        return new SyncOzonAccrualByDayHandler($em, $client, $lockFactory, new NullLogger(), $bus, $repository);
    }
}
