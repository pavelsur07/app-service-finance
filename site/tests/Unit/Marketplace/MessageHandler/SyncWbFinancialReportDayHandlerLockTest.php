<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportReconciliationService;
use App\Marketplace\Application\Service\WbFinancialReportSyncStatusUpdater;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\MessageHandler\SyncWbFinancialReportDayHandler;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncWbFinancialReportDayHandlerLockTest extends TestCase
{
    public function testBusinessDayLockIgnoresConnectionIdAndSkipsSecondMessage(): void
    {
        $companyId = '11111111-1111-4111-8111-111111111111';
        $firstConnectionId = '22222222-2222-4222-8222-222222222222';
        $secondConnectionId = '33333333-3333-4333-8333-333333333333';
        $businessDate = '2026-05-19';

        $lockFactory = new LockFactory(new InMemoryStore());
        $heldLock = $lockFactory->createLock(
            sprintf('marketplace_financial_report_sync:%s:%s:%s:%s', $companyId, 'wildberries', 'sales_report', $businessDate),
            600,
        );
        self::assertTrue($heldLock->acquire());

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->expects(self::never())->method('findByIdAndCompany');

        $financeClient = $this->uninitialized(WbFinanceSalesReportClient::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'WB day sync lock not acquired, skipping.',
                self::callback(static fn (array $context): bool => $context['connection_id'] === $secondConnectionId),
            );

        $handler = new SyncWbFinancialReportDayHandler(
            $this->createMock(EntityManagerInterface::class),
            $connectionRepository,
            new WbFinancialReportPeriodResolver(new MockClock('2026-05-20T00:00:00+03:00')),
            $financeClient,
            $this->uninitialized(WbFinancialReportSyncStatusUpdater::class),
            $this->uninitialized(WbFinancialReportReconciliationService::class),
            $lockFactory,
            $this->createMock(MessageBusInterface::class),
            $logger,
            new MockClock('2026-05-20T00:00:00+03:00'),
            60,
        );

        $handler(new SyncWbFinancialReportDayMessage(
            $companyId,
            $secondConnectionId,
            $businessDate,
            'daily',
            false,
        ));

        $heldLock->release();
        $this->addToAssertionCount(1);
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
