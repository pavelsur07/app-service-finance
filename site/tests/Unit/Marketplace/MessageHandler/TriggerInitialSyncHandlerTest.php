<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Marketplace\Application\Service\OzonAccrualSyncPlanner;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\SyncOzonAccrualByDayMessage;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\MessageHandler\TriggerInitialSyncHandler;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TriggerInitialSyncHandlerTest extends TestCase
{
    public function testWbInitialPlansDailySyncWithoutSixtyDaysCap(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES);

        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->willReturn(new \DateTimeImmutable('2026-01-10 00:00:00'));

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::once())
            ->method('planInitial')
            ->with(
                $company->getId(),
                $connection->getId(),
                self::callback(static function (\DateTimeImmutable $date): bool {
                    return '2026-01-10 00:00:00' === $date->format('Y-m-d H:i:s');
                }),
            )
            ->willReturn(120);

        $captured = new \stdClass();
        $captured->message = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function ($m) use ($captured) {
            $captured->message = $m;

            return new Envelope($m);
        });

        $handler = new TriggerInitialSyncHandler(
            new NullLogger(),
            new MockClock('2026-04-30 00:00:00'),
            $repo,
            $resolver,
            $planner,
            new OzonAccrualSyncPlanner($bus, new NullLogger(), new MockClock('2026-04-30 00:00:00')),
        );

        $handler(new TriggerInitialSyncMessage($company->getId(), $connection->getId(), MarketplaceType::WILDBERRIES->value));

        self::assertNull($captured->message);
    }

    public function testOzonSellerQueuesAccrualByDayFromSafeDayUntilYesterday(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-4222-8222-222222222222', $company, MarketplaceType::OZON);
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $resolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $resolver->expects(self::never())->method('resolve');

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::never())->method('planInitial');

        /** @var list<object> $messages */
        $messages = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $m) use (&$messages): Envelope {
            $messages[] = $m;

            return new Envelope($m);
        });

        $clock = new MockClock('2026-09-12 10:00:00', 'Europe/Moscow');
        $handler = new TriggerInitialSyncHandler(
            new NullLogger(),
            $clock,
            $repo,
            $resolver,
            $planner,
            new OzonAccrualSyncPlanner($bus, new NullLogger(), $clock),
        );

        $handler(new TriggerInitialSyncMessage((string) $company->getId(), $connection->getId(), MarketplaceType::OZON->value));

        // Окно 01.01 → 11.09 поднимается до порога 08.09: раньше лежат документы v3.
        self::assertContainsOnlyInstancesOf(SyncOzonAccrualByDayMessage::class, $messages);
        self::assertSame(
            ['2026-09-11', '2026-09-10', '2026-09-09', '2026-09-08'],
            array_map(static fn (SyncOzonAccrualByDayMessage $m): string => $m->date, $messages),
        );
        self::assertSame($connection->getId(), $messages[0]->connectionId);
    }

    public function testOzonPerformanceConnectionIsSkipped(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection(
            '33333333-3333-4333-8333-333333333333',
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('find')->willReturn($connection);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new TriggerInitialSyncHandler(
            new NullLogger(),
            new MockClock('2026-09-12 10:00:00'),
            $repo,
            $this->createMock(WbInitialSyncStartDateResolver::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
            new OzonAccrualSyncPlanner($bus, new NullLogger(), new MockClock('2026-09-12 10:00:00')),
        );

        $handler(new TriggerInitialSyncMessage((string) $company->getId(), $connection->getId(), MarketplaceType::OZON->value));
    }
}
