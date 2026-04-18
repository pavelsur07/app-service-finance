<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
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
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Idempotency regression: если за дату уже есть completed raw document,
 * handler обязан skip'нуть Ozon API и не создавать дубль raw document'а.
 *
 * Корневая причина бага (prod 18.04.2026): cron задвоил два SyncOzonReportMessage
 * за один день; lock marketplace_sync_{cid}_ozon_{date} сериализует одновременные
 * вызовы, но не защищает от повторного запуска после release'а. Оба воркера
 * создавали raw_document'ы, нарушая уникальность на этапе сохранения продаж.
 */
final class SyncOzonReportHandlerTest extends TestCase
{
    private const COMPANY_ID    = '11111111-1111-1111-1111-111111111111';
    private const CONNECTION_ID = '22222222-2222-2222-2222-222222222222';
    private const DATE          = '2026-04-17';

    public function testSkipsWhenCompletedRawDocumentAlreadyExists(): void
    {
        $company    = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);

        $day = new \DateTimeImmutable(self::DATE);

        $existingDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withPeriod($day, $day)
            ->build();
        $existingDoc->markCompleted();

        $em         = $this->createEmMock($company, $connection);
        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->expects(self::once())
            ->method('findOneBy')
            ->willReturn($existingDoc);

        // Ни к adapter'у, ни к persist/flush обращений быть не должно —
        // handler вышел по early-return'у.
        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->expects(self::never())->method('fetchRawReport');

        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(
            companyId:    self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            date:         self::DATE,
        ));
    }

    public function testProceedsWhenNoExistingRawDocument(): void
    {
        $company    = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);

        $em         = $this->createEmMock($company, $connection);
        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->expects(self::once())
            ->method('findOneBy')
            ->willReturn(null);

        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->method('getApiEndpointName')->willReturn('test/endpoint');
        $adapter->expects(self::once())
            ->method('fetchRawReport')
            ->willReturn([['operation_id' => 1]]);

        $em->expects(self::once())->method('persist');
        $em->expects(self::atLeastOnce())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(ProcessDayReportMessage::class))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(
            companyId:    self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            date:         self::DATE,
        ));
    }

    public function testProceedsWhenExistingDocumentHasFailedStatus(): void
    {
        $company    = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->build();
        $connection = new MarketplaceConnection(self::CONNECTION_ID, $company, MarketplaceType::OZON);

        $em         = $this->createEmMock($company, $connection);

        // Handler фильтрует по processingStatus = COMPLETED, поэтому репозиторий
        // получает запрос именно с этим статусом и возвращает null — документ
        // со status=failed в выборку не попадает. Проверяем, что handler
        // продолжает нормально и вызывает adapter.
        $rawDocRepo = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocRepo->expects(self::once())
            ->method('findOneBy')
            ->with(self::callback(static function (array $criteria): bool {
                return ($criteria['processingStatus'] ?? null)?->value === 'completed';
            }))
            ->willReturn(null);

        $adapter = $this->createMock(MarketplaceAdapterInterface::class);
        $adapter->method('getMarketplaceType')->willReturn(MarketplaceType::OZON->value);
        $adapter->method('getApiEndpointName')->willReturn('test/endpoint');
        $adapter->expects(self::once())
            ->method('fetchRawReport')
            ->willReturn([['operation_id' => 1]]);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler($em, $adapter, $messageBus, $rawDocRepo);
        $handler(new SyncOzonReportMessage(
            companyId:    self::COMPANY_ID,
            connectionId: self::CONNECTION_ID,
            date:         self::DATE,
        ));
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

    private function createHandler(
        EntityManagerInterface $em,
        MarketplaceAdapterInterface $adapter,
        MessageBusInterface $messageBus,
        MarketplaceRawDocumentRepository $rawDocRepo,
    ): SyncOzonReportHandler {
        $registry = new MarketplaceAdapterRegistry([$adapter]);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        return new SyncOzonReportHandler(
            $em,
            $registry,
            $lockFactory,
            new NullLogger(),
            $messageBus,
            $rawDocRepo,
        );
    }
}
