<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncOzonReportMessage;
use App\Marketplace\MessageHandler\SyncOzonReportHandler;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterInterface;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncOzonReportHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const COMPANY_2_ID = '33333333-3333-3333-3333-333333333333';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';
    private const DATE = '2026-04-17';

    public function testCreatesNewRawDocumentAndDispatchesProcessingMessage(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->method('findActiveExactDayDocuments')->willReturn([]);

        $capturedPersistedDoc = null;
        $em = $this->createEmMock($company, $connection);
        $em->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(MarketplaceRawDocument::class))
            ->willReturnCallback(function (object $entity) use (&$capturedPersistedDoc): void {
                $capturedPersistedDoc = $entity;
            });

        $adapter = $this->createAdapterMock([['operation_id' => 1], ['operation_id' => 2]]);

        $dispatchedMessage = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatchedMessage): Envelope {
            $dispatchedMessage = $message;

            return new Envelope($message);
        });

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertInstanceOf(MarketplaceRawDocument::class, $capturedPersistedDoc);
        self::assertSame(2, $capturedPersistedDoc->getRecordsCount());
        self::assertSame([['operation_id' => 1], ['operation_id' => 2]], $capturedPersistedDoc->getRawData());

        self::assertInstanceOf(ProcessDayReportMessage::class, $dispatchedMessage);
        self::assertSame($capturedPersistedDoc->getId(), $dispatchedMessage->rawDocumentId);
    }

    public function testRefreshesExistingRawDocumentAndDispatchesWithSameId(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);
        $existingDoc = $this->buildDocForDate($company);
        $existingDoc->setRawData([['old' => true]])
            ->setRecordsCount(1)
            ->setRecordsCreated(5)
            ->setRecordsSkipped(4)
            ->setUnprocessedCostsCount(3)
            ->setUnprocessedCostTypes(['X' => 3])
            ->markCompleted();

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->method('findActiveExactDayDocuments')->willReturn([$existingDoc]);

        $em = $this->createEmMock($company, $connection);
        $em->expects(self::never())->method('persist');

        $adapter = $this->createAdapterMock([['operation_id' => 100], ['operation_id' => 200], ['operation_id' => 300]]);

        $dispatchedMessage = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatchedMessage): Envelope {
            $dispatchedMessage = $message;

            return new Envelope($message);
        });

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $existingId = $existingDoc->getId();
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertSame($existingId, $existingDoc->getId());
        self::assertSame([['operation_id' => 100], ['operation_id' => 200], ['operation_id' => 300]], $existingDoc->getRawData());
        self::assertSame(3, $existingDoc->getRecordsCount());
        self::assertSame(PipelineStatus::PENDING, $existingDoc->getProcessingStatus());
        self::assertSame(0, $existingDoc->getRecordsCreated());
        self::assertSame(0, $existingDoc->getRecordsSkipped());
        self::assertSame(0, $existingDoc->getUnprocessedCostsCount());
        self::assertNull($existingDoc->getUnprocessedCostTypes());

        self::assertInstanceOf(ProcessDayReportMessage::class, $dispatchedMessage);
        self::assertSame($existingId, $dispatchedMessage->rawDocumentId);
    }


    public function testUsesCanonicalDocumentWhenMultipleActiveDuplicatesExist(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);

        $canonicalDoc = $this->buildDocForDate($company);
        $canonicalDoc->setRawData([['old' => 'canonical']])->setRecordsCount(1)->markCompleted();

        $duplicateDoc = $this->buildDocForDate($company);
        $duplicateDoc->setRawData([['old' => 'duplicate']])->setRecordsCount(1)->markCompleted();

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->method('findActiveExactDayDocuments')->willReturn([$canonicalDoc, $duplicateDoc]);

        $em = $this->createEmMock($company, $connection);
        $em->expects(self::never())->method('persist');

        $adapter = $this->createAdapterMock([['operation_id' => 901], ['operation_id' => 902]]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('Multiple active Ozon raw documents found for day'),
                self::arrayHasKey('raw_document_ids'),
            );

        $dispatchedMessage = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatchedMessage): Envelope {
            $dispatchedMessage = $message;

            return new Envelope($message);
        });

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo, $logger);
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertSame([['operation_id' => 901], ['operation_id' => 902]], $canonicalDoc->getRawData());
        self::assertSame(2, $canonicalDoc->getRecordsCount());

        self::assertSame([['old' => 'duplicate']], $duplicateDoc->getRawData());
        self::assertSame(1, $duplicateDoc->getRecordsCount());

        self::assertInstanceOf(ProcessDayReportMessage::class, $dispatchedMessage);
        self::assertSame($canonicalDoc->getId(), $dispatchedMessage->rawDocumentId);
    }

    public function testEmptyResponseDoesNotOverwriteExistingDocumentAndDoesNotDispatch(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);
        $existingDoc = $this->buildDocForDate($company);
        $existingDoc->setRawData([['keep' => 1]])->setRecordsCount(1);

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->method('findActiveExactDayDocuments')->willReturn([$existingDoc]);

        $em = $this->createEmMock($company, $connection);
        $em->expects(self::never())->method('persist');

        $adapter = $this->createAdapterMock([]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertSame([['keep' => 1]], $existingDoc->getRawData());
        self::assertSame(1, $existingDoc->getRecordsCount());
    }

    public function testSkipsWhenPendingRawDocumentExists(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);
        $doc = $this->buildDocForDate($company);
        $doc->resetProcessingStatus();
        self::assertSame(PipelineStatus::PENDING, $doc->getProcessingStatus());

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->method('findActiveExactDayDocuments')->willReturn([$doc]);

        $em = $this->createEmMock($company, $connection);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->expects(self::never())->method('fetchRawReport');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));
    }

    public function testSkipsWhenRunningRawDocumentExists(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);
        $doc = $this->buildDocForDate($company);
        $this->forceProcessingStatus($doc, PipelineStatus::RUNNING);

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->method('findActiveExactDayDocuments')->willReturn([$doc]);

        $em = $this->createEmMock($company, $connection);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->expects(self::never())->method('fetchRawReport');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));
    }

    public function testHandlerLooksUpExistingRawDocumentByCurrentCompany(): void
    {
        $company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $otherCompany = CompanyBuilder::aCompany()->withId(self::COMPANY_2_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);
        $otherCompanyDoc = $this->buildDocForDate($otherCompany);
        $otherCompanyDoc->setRawData([['other' => 1]])->setRecordsCount(1);

        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->expects(self::once())
            ->method('findActiveExactDayDocuments')
            ->with(
                self::identicalTo($company),
                self::identicalTo(MarketplaceType::OZON),
                self::identicalTo('sales_report'),
                self::callback(static fn (\DateTimeImmutable $d): bool => $d->format('Y-m-d') === self::DATE),
            )
            ->willReturn([]);

        $em = $this->createEmMock($company, $connection);
        $em->expects(self::once())->method('persist');

        $adapter = $this->createAdapterMock([['current' => 1]]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(self::COMPANY_ID, self::CONNECTION_ID, self::DATE));

        self::assertSame([['other' => 1]], $otherCompanyDoc->getRawData());
        self::assertSame(1, $otherCompanyDoc->getRecordsCount());
    }

    private function buildDocForDate(Company $company): MarketplaceRawDocument
    {
        $day = new \DateTimeImmutable(self::DATE);

        return MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withPeriod($day, $day)
            ->build();
    }

    private function createEmMock(Company $company, MarketplaceConnection $connection): EntityManagerInterface
    {
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

        return $em;
    }

    private function forceProcessingStatus(MarketplaceRawDocument $doc, PipelineStatus $status): void
    {
        $reflection = new \ReflectionProperty($doc, 'processingStatus');
        $reflection->setValue($doc, $status);
    }

    private function createAdapterMock(array $rawData): MarketplaceAdapterInterface
    {
        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->method('getApiEndpointName')->willReturn('test/endpoint');
        $adapter->method('fetchRawReport')->willReturn($rawData);

        return $adapter;
    }

    private function createHandler(EntityManagerInterface $em, MarketplaceAdapterInterface $adapter, MessageBusInterface $messageBus, MarketplaceRawDocumentRepository $rawDocRepo, ?LoggerInterface $logger = null): SyncOzonReportHandler
    {
        $registry = new MarketplaceAdapterRegistry([$adapter]);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        return new SyncOzonReportHandler($em, $registry, $lockFactory, $logger ?? new NullLogger(), $messageBus, $rawDocRepo);
    }
}
