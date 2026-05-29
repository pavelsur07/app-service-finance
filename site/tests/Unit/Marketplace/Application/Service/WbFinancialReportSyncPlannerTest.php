<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlanner;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\Repository\MarketplaceFinancialReportSyncStatusLookupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class WbFinancialReportSyncPlannerTest extends TestCase
{
    private ActiveWbConnectionsQuery&MockObject $connections;
    private MarketplaceFinancialReportSyncStatusLookupInterface&MockObject $statuses;
    private MessageBusInterface&MockObject $bus;
    private ClockInterface $clock;

    /** @var list<SyncWbFinancialReportDayMessage> */
    private array $dispatchedMessages = [];

    private \Closure $claimForQueueCallback;

    protected function setUp(): void
    {
        $this->connections = $this->createMock(ActiveWbConnectionsQuery::class);
        $this->statuses = $this->createMock(MarketplaceFinancialReportSyncStatusLookupInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->clock = new MockClock('2026-05-21T10:00:00+03:00');
        $this->bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($message instanceof SyncWbFinancialReportDayMessage) {
                $this->dispatchedMessages[] = $message;
            }

            return new Envelope($message);
        });
        $this->claimForQueueCallback = function (
            string $connectionId,
            string $companyId,
            MarketplaceType $marketplace,
            string $reportType,
            string $apiEndpoint,
            \DateTimeImmutable $businessDate,
            FinancialReportSyncMode $mode,
            bool $forceRefresh,
            \DateTimeImmutable $now,
        ): MarketplaceFinancialReportSyncStatus {
            return $this->statusEntity(
                $businessDate->format('Y-m-d'),
                FinancialReportSyncStatus::QUEUED,
                null,
                $now->format(DATE_ATOM),
            );
        };
        $this->statuses->method('claimForQueue')->willReturnCallback(
            fn (...$args): ?MarketplaceFinancialReportSyncStatus => ($this->claimForQueueCallback)(...$args),
        );
    }

    public function testPlanRefresh14DaysDispatchesAtMostOnePerConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
        self::assertCount(1, $this->dispatchedMessages);
    }

    public function testPlanRefresh14DaysDispatchesAtMostThreePerConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
            $this->statusEntity('2026-05-18', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
            $this->statusEntity('2026-05-17', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(3, $planner->planRefresh14Days(null, null, 3));
        self::assertCount(3, $this->dispatchedMessages);
    }

    public function testFailedWithFutureRetryAtIsNotDispatched(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, '2026-05-21T11:00:00+03:00', '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(0, $planner->planRefresh14Days(null, null, 1));
        self::assertCount(0, $this->dispatchedMessages);
    }

    public function testFailedWithRetryDueIsDispatched(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, '2026-05-21T09:59:00+03:00', '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
    }

    public function testFailedWithNullRetryAtIsDispatched(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, null, '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
    }

    public function testSuccessAndEmptyArePrioritizedByOldestUpdatedAt(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::SUCCESS, null, '2026-05-21T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::EMPTY, null, '2026-05-18T09:00:00+03:00'),
        ]);

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
        self::assertSame('2026-05-19', $this->dispatchedMessages[0]->dateFrom);
    }

    public function testLoadingAndProcessingAreNotDispatched(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::LOADING, null, '2026-05-21T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::PROCESSING, null, '2026-05-21T09:00:00+03:00'),
        ]);

        self::assertSame(0, $planner->planRefresh14Days(null, null, 2));
    }


    public function testUnknownDaysAreDispatchedWhenNoFailedOrSuccessCandidates(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([]);

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
        self::assertCount(1, $this->dispatchedMessages);
        self::assertSame('refresh14', $this->dispatchedMessages[0]->mode);

        $last14days = (new WbFinancialReportPeriodResolver(new MockClock('2026-05-21 00:00:00 Europe/Moscow')))
            ->last14Days();
        $expected = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $last14days);
        self::assertContains($this->dispatchedMessages[0]->dateFrom, $expected);
    }
    public function testUnknownDaysGoAfterFailedDueAndSuccessEmpty(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, null, '2026-05-21T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::SUCCESS, null, '2026-05-18T09:00:00+03:00'),
        ]);

        self::assertSame(2, $planner->planRefresh14Days(null, null, 2));
        self::assertSame('2026-05-20', $this->dispatchedMessages[0]->dateFrom);
        self::assertSame('2026-05-19', $this->dispatchedMessages[1]->dateFrom);
    }

    public function testLimitIsAppliedPerConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co2')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, null, '2026-05-21T09:00:00+03:00'),
        ]);

        self::assertSame(2, $planner->planRefresh14Days(null, null, 1));
    }

    public function testPlanInitialRespectsMaxDaysPerConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co2')]);

        self::assertSame(2, $planner->planInitial(null, null, new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'), 1));
        self::assertSame(['2026-05-18', '2026-05-18'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
    }

    public function testPlanInitialUsesClaimForQueueToSkipBlockedDays(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->claimForQueueCallback = function (
            string $connectionId,
            string $companyId,
            MarketplaceType $marketplace,
            string $reportType,
            string $apiEndpoint,
            \DateTimeImmutable $businessDate,
            FinancialReportSyncMode $mode,
            bool $forceRefresh,
            \DateTimeImmutable $now,
        ): ?MarketplaceFinancialReportSyncStatus {
            if ('2026-05-18' === $businessDate->format('Y-m-d')) {
                return null;
            }

            return $this->statusEntity(
                $businessDate->format('Y-m-d'),
                FinancialReportSyncStatus::QUEUED,
                null,
                $now->format(DATE_ATOM),
            );
        };

        self::assertSame(1, $planner->planInitial(null, null, new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'), 1));
        self::assertSame(['2026-05-19'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
    }

    public function testPlanMissingRespectsMaxDays(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([]);
        $this->statuses->method('findRetryDueDays')->willReturn([]);

        self::assertSame(2, $planner->planMissing(null, null, 2));
    }



    public function testPlanMissingLimitIsAppliedPerConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co2')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([]);
        $this->statuses->method('findRetryDueDays')->willReturnCallback(static fn (string $companyId): array => [new \DateTimeImmutable('2026-01-01 00:00:00 Europe/Moscow')]);

        self::assertSame(2, $planner->planMissing(null, null, 1));
        self::assertCount(2, $this->dispatchedMessages);
    }

    public function testPlanMissingSkipsLoadingAndProcessingStatuses(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findRetryDueDays')->willReturn([]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-01-01', FinancialReportSyncStatus::LOADING, null, '2026-01-05T09:00:00+03:00'),
            $this->statusEntity('2026-01-02', FinancialReportSyncStatus::PROCESSING, null, '2026-01-05T09:00:00+03:00'),
        ]);

        self::assertSame(2, $planner->planMissing(null, null, 10));
        self::assertSame(['2026-01-03', '2026-01-04'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
    }
    private function planner(): WbFinancialReportSyncPlanner
    {
        return new WbFinancialReportSyncPlanner(
            $this->connections,
            new WbFinancialReportPeriodResolver(new MockClock('2026-05-21 00:00:00 Europe/Moscow')),
            $this->statuses,
            $this->bus,
            $this->clock,
        );
    }

    private function statusEntity(string $day, FinancialReportSyncStatus $status, ?string $nextRetryAt, string $updatedAt): MarketplaceFinancialReportSyncStatus&MockObject
    {
        $entity = $this->createMock(MarketplaceFinancialReportSyncStatus::class);
        $entity->method('getBusinessDate')->willReturn(new \DateTimeImmutable($day.' 00:00:00 Europe/Moscow'));
        $entity->method('getStatus')->willReturn($status);
        $entity->method('getNextRetryAt')->willReturn(null !== $nextRetryAt ? new \DateTimeImmutable($nextRetryAt) : null);
        $entity->method('getUpdatedAt')->willReturn(new \DateTimeImmutable($updatedAt));

        return $entity;
    }

    /** @return array{id: string, connection_id: string, company_id: string} */
    private function conn(string $connectionId, string $companyId): array
    {
        return ['id' => $connectionId, 'connection_id' => $connectionId, 'company_id' => $companyId];
    }
}
