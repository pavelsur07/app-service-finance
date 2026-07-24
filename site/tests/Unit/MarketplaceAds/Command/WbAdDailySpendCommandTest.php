<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Application\DTO\WbAdSpendLoadResult;
use App\MarketplaceAds\Application\LoadWbAdSpendDayActionInterface;
use App\MarketplaceAds\Command\WbAdDailySpendCommand;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class WbAdDailySpendCommandTest extends TestCase
{
    private const COMPANY_1 = '11111111-1111-1111-1111-000000000001';
    private const COMPANY_2 = '11111111-1111-1111-1111-000000000002';
    private const CONNECTION_1 = '22222222-2222-2222-2222-000000000001';
    private const CONNECTION_2 = '22222222-2222-2222-2222-000000000002';

    public function testLoadsYesterdayInMoscowForEveryConnection(): void
    {
        $facade = $this->facadeWithConnections($this->connections());
        $seen = [];
        $action = $this->createMock(LoadWbAdSpendDayActionInterface::class);
        $action
            ->expects(self::exactly(2))
            ->method('__invoke')
            ->willReturnCallback(function (string $companyId, string $connectionId, \DateTimeImmutable $date) use (&$seen): WbAdSpendLoadResult {
                $seen[] = [$companyId, $connectionId, $date->format('Y-m-d'), $date->getTimezone()->getName()];

                return $this->loadResult(AdRawDocumentStatus::PROCESSED);
            });

        // 21:30 UTC is already 00:30 on July 22 in Moscow, so yesterday is July 21.
        $tester = $this->tester(
            $facade,
            $action,
            new MockClock('2026-07-21T21:30:00+00:00'),
        );

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([
            [self::COMPANY_1, self::CONNECTION_1, '2026-07-21', 'Europe/Moscow'],
            [self::COMPANY_2, self::CONNECTION_2, '2026-07-21', 'Europe/Moscow'],
        ], $seen);
        self::assertStringContainsString(
            'WB ad spend date=2026-07-21 loaded=2 review_required=0 failed=0',
            $tester->getDisplay(),
        );
    }

    public function testExplicitDateAndConnectionFilterLoadOnlySelectedConnection(): void
    {
        $facade = $this->facadeWithConnections($this->connections(), self::COMPANY_2);
        $action = $this->createMock(LoadWbAdSpendDayActionInterface::class);
        $action
            ->expects(self::once())
            ->method('__invoke')
            ->with(
                self::COMPANY_2,
                self::CONNECTION_2,
                self::callback(static fn (\DateTimeImmutable $date): bool => '2026-07-10' === $date->format('Y-m-d')),
            )
            ->willReturn($this->loadResult(AdRawDocumentStatus::DRAFT));

        $tester = $this->tester($facade, $action);
        $exitCode = $tester->execute([
            '--date' => '2026-07-10',
            '--company-id' => self::COMPANY_2,
            '--connection-id' => self::CONNECTION_2,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('review_required=1', $tester->getDisplay());
    }

    public function testContinuesAfterConnectionFailureAndReturnsFailure(): void
    {
        $facade = $this->facadeWithConnections($this->connections());
        $attempted = [];
        $action = $this->createMock(LoadWbAdSpendDayActionInterface::class);
        $action
            ->expects(self::exactly(2))
            ->method('__invoke')
            ->willReturnCallback(function (string $companyId) use (&$attempted): WbAdSpendLoadResult {
                $attempted[] = $companyId;
                if (self::COMPANY_1 === $companyId) {
                    throw new \RuntimeException('WB unavailable');
                }

                return $this->loadResult(AdRawDocumentStatus::PROCESSED);
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'WB daily ad spend connection failed.',
                self::callback(static fn (array $context): bool => self::COMPANY_1 === ($context['companyId'] ?? null)),
            );

        $tester = $this->tester($facade, $action, logger: $logger);

        self::assertSame(Command::FAILURE, $tester->execute(['--date' => '2026-07-10']));
        self::assertSame([self::COMPANY_1, self::COMPANY_2], $attempted);
        self::assertStringContainsString('loaded=1 review_required=0 failed=1', $tester->getDisplay());
    }

    public function testRejectsInvalidOrIncompleteDateBeforeLoadingConnections(): void
    {
        foreach (['2026-7-1', '2026-07-22', '2026-07-23'] as $value) {
            $facade = $this->createMock(MarketplaceFacade::class);
            $facade->expects(self::never())->method('getActiveWbSellerConnections');
            $action = $this->createMock(LoadWbAdSpendDayActionInterface::class);
            $action->expects(self::never())->method('__invoke');

            $tester = $this->tester($facade, $action);

            self::assertSame(Command::INVALID, $tester->execute(['--date' => $value]));
        }
    }

    public function testNoConnectionsExitsSuccessfully(): void
    {
        $facade = $this->facadeWithConnections([]);
        $action = $this->createMock(LoadWbAdSpendDayActionInterface::class);
        $action->expects(self::never())->method('__invoke');

        $tester = $this->tester($facade, $action);

        self::assertSame(Command::SUCCESS, $tester->execute(['--date' => '2026-07-10']));
        self::assertStringContainsString('No matching active Wildberries', $tester->getDisplay());
    }

    public function testConcurrentInvocationIsSkippedByLock(): void
    {
        $store = new InMemoryStore();
        $factory = new LockFactory($store);
        $heldLock = $factory->createLock('app:marketplace-ads:wb-daily-spend');
        self::assertTrue($heldLock->acquire());

        try {
            $facade = $this->createMock(MarketplaceFacade::class);
            $facade->expects(self::never())->method('getActiveWbSellerConnections');
            $action = $this->createMock(LoadWbAdSpendDayActionInterface::class);
            $action->expects(self::never())->method('__invoke');
            $command = new WbAdDailySpendCommand(
                $facade,
                $action,
                new MockClock('2026-07-22 12:00:00 Europe/Moscow'),
                $this->createMock(LoggerInterface::class),
            );
            $property = new \ReflectionProperty($command, 'lockFactory');
            $property->setValue($command, $factory);
            $tester = new CommandTester($command);

            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('Another WB ad spend load is running', $tester->getDisplay());
        } finally {
            $heldLock->release();
        }
    }

    /**
     * @param list<array{connectionId: string, companyId: string, marketplace: string, connectionType: string}> $connections
     */
    private function facadeWithConnections(array $connections, ?string $expectedCompanyId = null): MarketplaceFacade
    {
        $facade = $this->createMock(MarketplaceFacade::class);
        $facade
            ->expects(self::once())
            ->method('getActiveWbSellerConnections')
            ->with($expectedCompanyId)
            ->willReturn($connections);

        return $facade;
    }

    private function tester(
        MarketplaceFacade $facade,
        LoadWbAdSpendDayActionInterface $action,
        ?MockClock $clock = null,
        ?LoggerInterface $logger = null,
    ): CommandTester {
        return new CommandTester(new WbAdDailySpendCommand(
            $facade,
            $action,
            $clock ?? new MockClock('2026-07-22 12:00:00 Europe/Moscow'),
            $logger ?? $this->createMock(LoggerInterface::class),
        ));
    }

    /**
     * @return list<array{connectionId: string, companyId: string, marketplace: string, connectionType: string}>
     */
    private function connections(): array
    {
        return [
            [
                'connectionId' => self::CONNECTION_1,
                'companyId' => self::COMPANY_1,
                'marketplace' => 'wildberries',
                'connectionType' => 'seller',
            ],
            [
                'connectionId' => self::CONNECTION_2,
                'companyId' => self::COMPANY_2,
                'marketplace' => 'wildberries',
                'connectionType' => 'seller',
            ],
        ];
    }

    private function loadResult(AdRawDocumentStatus $status): WbAdSpendLoadResult
    {
        return new WbAdSpendLoadResult(
            rawDocumentId: '33333333-3333-3333-3333-333333333333',
            status: $status,
            campaignCount: 2,
            skuCount: 3,
            attributedTotal: '10.00',
            unallocatedTotal: '1.00',
            actualTotal: '11.00',
        );
    }
}
