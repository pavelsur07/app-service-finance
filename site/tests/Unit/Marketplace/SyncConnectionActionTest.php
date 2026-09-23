<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Application\Command\SyncConnectionCommand;
use App\Marketplace\Application\Service\OzonAccrualSyncPlanner;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanResult;
use App\Marketplace\Application\SyncConnectionAction;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\ManualSyncNotSupportedException;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncConnectionActionTest extends TestCase
{
    public function testWildberriesManualSyncSchedulesPlannerTasksInsteadOfFetchingRawReport(): void
    {
        $companyId = '11111111-1111-4111-8111-111111111111';
        $connectionId = '22222222-2222-4222-8222-222222222222';
        $company = new Company($companyId, self::uninitialized(User::class));
        $connection = new MarketplaceConnection($connectionId, $company, MarketplaceType::WILDBERRIES);

        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->expects(self::once())
            ->method('find')
            ->with($connectionId)
            ->willReturn($connection);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::once())
            ->method('planRangeLimited')
            ->with(
                self::callback(
                    static fn (\DateTimeImmutable $date): bool => '2026-02-10 00:00:00 Europe/Moscow'
                        === $date->format('Y-m-d H:i:s e'),
                ),
                self::callback(
                    static fn (\DateTimeImmutable $date): bool => '2026-02-10 00:00:00 Europe/Moscow'
                        === $date->format('Y-m-d H:i:s e'),
                ),
                FinancialReportSyncMode::MANUAL,
                31,
                $companyId,
                $connectionId,
                true,
            )
            ->willReturn(new WbFinancialReportSyncPlanResult(
                candidatesCount: 1,
                dispatchLimit: 31,
                attemptedCount: 1,
                dispatchedCount: 1,
                skippedByLimitCount: 0,
            ));

        $action = new SyncConnectionAction($repository, $entityManager, $planner, $this->ozonPlanner($bus));

        $scheduledCount = $action(new SyncConnectionCommand(
            companyId: $companyId,
            connectionId: $connectionId,
            fromDate: new \DateTimeImmutable('2026-02-10 12:30:00 UTC'),
            toDate: new \DateTimeImmutable('2026-02-10 23:59:59 UTC'),
        ));

        self::assertSame(1, $scheduledCount->scheduledCount);
        self::assertNull($connection->getLastSuccessfulSyncAt());
    }

    public function testOzonSellerManualSyncSchedulesAccrualByDayTasks(): void
    {
        $companyId = '11111111-1111-4111-8111-111111111111';
        $connectionId = '33333333-3333-4333-8333-333333333333';
        $connection = new MarketplaceConnection($connectionId, new Company($companyId, self::uninitialized(User::class)), MarketplaceType::OZON);

        /** @var list<SyncOzonAccrualByDayMessage> $messages */
        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });

        $wbPlanner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $wbPlanner->expects(self::never())->method('planRangeLimited');

        $action = new SyncConnectionAction(
            $this->repositoryReturning($connectionId, $connection),
            $this->createMock(EntityManagerInterface::class),
            $wbPlanner,
            $this->ozonPlanner($bus),
        );

        // «Сегодня» 20.09: окно 06.09–20.09 режется снизу порогом 08.09 и сверху вчерашним днём.
        $result = $action(new SyncConnectionCommand(
            companyId: $companyId,
            connectionId: $connectionId,
            fromDate: new \DateTimeImmutable('2026-09-06'),
            toDate: new \DateTimeImmutable('2026-09-20 23:59:59'),
        ));

        self::assertSame(12, $result->scheduledCount);
        self::assertSame('2026-09-08', $result->firstDay);
        self::assertSame('2026-09-19', $result->lastDay);
        self::assertTrue($result->clampedToSafeDay);
        self::assertCount(12, $messages);
        self::assertContainsOnlyInstancesOf(SyncOzonAccrualByDayMessage::class, $messages);
        self::assertSame($connectionId, $messages[0]->connectionId);
        self::assertSame($companyId, $messages[0]->companyId);
    }

    public function testOzonPerformanceConnectionIsNotSyncedManually(): void
    {
        $companyId = '11111111-1111-4111-8111-111111111111';
        $connectionId = '44444444-4444-4444-8444-444444444444';
        $connection = new MarketplaceConnection(
            $connectionId,
            new Company($companyId, self::uninitialized(User::class)),
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $action = new SyncConnectionAction(
            $this->repositoryReturning($connectionId, $connection),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
            $this->ozonPlanner($bus),
        );

        $this->expectException(ManualSyncNotSupportedException::class);

        $action(new SyncConnectionCommand($companyId, $connectionId, new \DateTimeImmutable('2026-09-10'), new \DateTimeImmutable('2026-09-12')));
    }

    public function testConnectionOfAnotherCompanyIsNotFound(): void
    {
        $connectionId = '55555555-5555-4555-8555-555555555555';
        $connection = new MarketplaceConnection(
            $connectionId,
            new Company('11111111-1111-4111-8111-111111111111', self::uninitialized(User::class)),
            MarketplaceType::OZON,
        );

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $action = new SyncConnectionAction(
            $this->repositoryReturning($connectionId, $connection),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
            $this->ozonPlanner($bus),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Подключение не найдено');

        $action(new SyncConnectionCommand(
            '99999999-9999-4999-8999-999999999999',
            $connectionId,
            new \DateTimeImmutable('2026-09-10'),
            new \DateTimeImmutable('2026-09-12'),
        ));
    }

    private function ozonPlanner(MessageBusInterface $bus): OzonAccrualSyncPlanner
    {
        return new OzonAccrualSyncPlanner($bus, new NullLogger(), new MockClock('2026-09-20 09:00:00', 'Europe/Moscow'));
    }

    private function repositoryReturning(string $connectionId, MarketplaceConnection $connection): MarketplaceConnectionRepository
    {
        $repository = $this->createMock(MarketplaceConnectionRepository::class);
        $repository->method('find')->with($connectionId)->willReturn($connection);

        return $repository;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    private static function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
