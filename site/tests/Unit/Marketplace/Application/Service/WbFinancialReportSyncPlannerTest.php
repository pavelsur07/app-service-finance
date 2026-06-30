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

    public function testQueuedWithFutureRetryAtIsNotDispatched(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::QUEUED, '2026-05-21T11:00:00+03:00', '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(0, $planner->planRefresh14Days(null, null, 1));
        self::assertSame([], $this->dispatchedMessages);
    }

    public function testQueuedContinuationDueIsDispatchedWithCursor(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::QUEUED, '2026-05-21T09:59:00+03:00', '2026-05-20T09:00:00+03:00'),
        ]);
        $this->claimForQueueCallback = fn (
            string $connectionId,
            string $companyId,
            MarketplaceType $marketplace,
            string $reportType,
            string $apiEndpoint,
            \DateTimeImmutable $businessDate,
            FinancialReportSyncMode $mode,
            bool $forceRefresh,
            \DateTimeImmutable $now,
        ): MarketplaceFinancialReportSyncStatus => $this->statusEntity(
            $businessDate->format('Y-m-d'),
            FinancialReportSyncStatus::QUEUED,
            null,
            $now->format(DATE_ATOM),
            123,
            'bbbbbbbb-bbbb-4bbb-8bbb-000000009999',
        );

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
        self::assertSame(123, $this->dispatchedMessages[0]->rrdId);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-000000009999', $this->dispatchedMessages[0]->rawDocumentId);
    }

    public function testFailedWithFutureRetryAtIsNotDispatched(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, '2026-05-21T11:00:00+03:00', '2026-05-20T09:00:00+03:00'),
        ]);

        self::assertSame(0, $planner->planRefresh14Days(null, null, 1));
        self::assertSame([], $this->dispatchedMessages);
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
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::SUCCESS, null, '2026-05-20T08:00:00+03:00', null, null, FinancialReportSyncMode::DAILY),
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::SUCCESS, null, '2026-05-21T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::EMPTY, null, '2026-05-19T08:00:00+03:00', null, null, FinancialReportSyncMode::DAILY),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::EMPTY, null, '2026-05-18T09:00:00+03:00'),
        ]);

        self::assertSame(1, $planner->planRefresh14Days(null, null, 1));
        self::assertSame('2026-05-19', $this->dispatchedMessages[0]->businessDate);
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
        self::assertSame([], $this->dispatchedMessages);
    }

    public function testRefreshDoesNotReplaceMissingWhenDailyIsAbsent(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([]);

        self::assertSame(0, $planner->planRefresh14Days(null, null, 1));
        self::assertSame([], $this->dispatchedMessages);
    }

    public function testRefreshSuccessRequiresDailySuccess(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::SUCCESS, null, '2026-05-20T09:00:00+03:00', null, null, FinancialReportSyncMode::REFRESH_14D),
        ]);

        self::assertSame(0, $planner->planRefreshRecentDays(null, null, 1, 1));
        self::assertSame([], $this->dispatchedMessages);
    }

    public function testRefreshCanPlanAfterDailySuccessWithoutExistingRefresh(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::SUCCESS, null, '2026-05-20T09:00:00+03:00', null, null, FinancialReportSyncMode::DAILY),
        ]);

        self::assertSame(1, $planner->planRefreshRecentDays(null, null, 1, 1));
        self::assertSame('2026-05-20', $this->dispatchedMessages[0]->businessDate);
        self::assertSame('refresh_14d', $this->dispatchedMessages[0]->mode);
    }

    public function testRefreshDoesNotMixModesWhenRefreshFutureRetryExists(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::SUCCESS, null, '2026-05-20T09:00:00+03:00', null, null, FinancialReportSyncMode::DAILY),
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::QUEUED, '2026-05-21T11:00:00+03:00', '2026-05-20T10:00:00+03:00', null, null, FinancialReportSyncMode::REFRESH_14D),
        ]);

        self::assertSame(0, $planner->planRefreshRecentDays(null, null, 1, 1));
        self::assertSame([], $this->dispatchedMessages);
    }

    public function testUnknownDaysGoAfterFailedDueAndSuccessEmpty(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::FAILED, null, '2026-05-21T09:00:00+03:00'),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::SUCCESS, null, '2026-05-17T09:00:00+03:00', null, null, FinancialReportSyncMode::DAILY),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::SUCCESS, null, '2026-05-18T09:00:00+03:00'),
        ]);

        self::assertSame(2, $planner->planRefresh14Days(null, null, 2));
        self::assertSame('2026-05-20', $this->dispatchedMessages[0]->businessDate);
        self::assertSame('2026-05-19', $this->dispatchedMessages[1]->businessDate);
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

    public function testPlanRangeLimitedKeepsTryingWhenFirstCandidateIsNotClaimable(): void
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

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            1,
        );

        self::assertSame(3, $result->candidatesCount);
        self::assertSame(2, $result->attemptedCount);
        self::assertSame(1, $result->dispatchedCount);
        self::assertSame(1, $result->skippedByLimitCount);
        self::assertSame(['2026-05-19'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
    }

    public function testPlanRangeLimitedKeepsTryingWhenFirstTwoCandidatesAreNotClaimable(): void
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
            if (\in_array($businessDate->format('Y-m-d'), ['2026-05-18', '2026-05-19'], true)) {
                return null;
            }

            return $this->statusEntity(
                $businessDate->format('Y-m-d'),
                FinancialReportSyncStatus::QUEUED,
                null,
                $now->format(DATE_ATOM),
            );
        };

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            1,
        );

        self::assertSame(3, $result->candidatesCount);
        self::assertSame(3, $result->attemptedCount);
        self::assertSame(1, $result->dispatchedCount);
        self::assertSame(0, $result->skippedByLimitCount);
        self::assertSame(['2026-05-20'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
    }

    public function testPlanRangeLimitedInitialFromToDispatchesOneWithConnectionAndCompanyFilter(): void
    {
        $planner = $this->planner();
        $this->connections
            ->expects(self::once())
            ->method('execute')
            ->with('co1', 'c1')
            ->willReturn([$this->conn('c1', 'co1')]);

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            1,
            'co1',
            'c1',
            true,
        );

        self::assertSame(3, $result->candidatesCount);
        self::assertSame(1, $result->dispatchLimit);
        self::assertSame(1, $result->attemptedCount);
        self::assertSame(1, $result->dispatchedCount);
        self::assertSame(2, $result->skippedByLimitCount);
        self::assertSame(['2026-05-18'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
        self::assertSame(['c1'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->connectionId, $this->dispatchedMessages));
    }

    public function testPlanRangeLimitedInitialFromToDispatchesThreeInAscendingDateOrder(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            3,
            'co1',
            'c1',
            true,
        );

        self::assertSame(3, $result->candidatesCount);
        self::assertSame(3, $result->dispatchLimit);
        self::assertSame(3, $result->attemptedCount);
        self::assertSame(3, $result->dispatchedCount);
        self::assertSame(0, $result->skippedByLimitCount);
        self::assertSame(['2026-05-18', '2026-05-19', '2026-05-20'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages));
    }

    public function testPlanRangeLimitedInitialDateDispatchesOneGlobally(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co2')]);

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-19 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-19 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            1,
        );

        self::assertSame(2, $result->candidatesCount);
        self::assertSame(1, $result->dispatchLimit);
        self::assertSame(1, $result->attemptedCount);
        self::assertSame(1, $result->dispatchedCount);
        self::assertSame(1, $result->skippedByLimitCount);
        self::assertSame(['c1'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->connectionId, $this->dispatchedMessages));
    }

    public function testPlanRangeLimitedInitialDateWithConnectionIdDispatchesOnlyThatConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co2')]);

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-19 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-19 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            1,
            null,
            'c2',
        );

        self::assertSame(1, $result->candidatesCount);
        self::assertSame(1, $result->attemptedCount);
        self::assertSame(1, $result->dispatchedCount);
        self::assertSame(0, $result->skippedByLimitCount);
        self::assertSame(['c2'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->connectionId, $this->dispatchedMessages));
    }

    public function testPlanRangeLimitedConnectionIdDoesNotPlanOtherConnections(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co1')]);

        $result = $planner->planRangeLimited(
            new \DateTimeImmutable('2026-05-18 00:00:00 Europe/Moscow'),
            new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'),
            FinancialReportSyncMode::INITIAL,
            10,
            'co1',
            'c1',
        );

        self::assertSame(3, $result->candidatesCount);
        self::assertSame(3, $result->attemptedCount);
        self::assertSame(3, $result->dispatchedCount);
        self::assertSame(['c1', 'c1', 'c1'], array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->connectionId, $this->dispatchedMessages));
    }

    public function testPlanMissingRespectsMaxDays(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([]);
        $this->statuses->method('findRetryDueDays')->willReturn([]);

        self::assertSame(2, $planner->planMissing(null, null, 2));
    }

    public function testPlanMissingRecoversDueQueuedContinuationWithCursor(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-01-01', FinancialReportSyncStatus::QUEUED, '2026-05-21T09:59:00+03:00', '2026-05-20T09:00:00+03:00'),
        ]);
        $this->statuses->method('findRetryDueDays')->willReturn([['business_date' => new \DateTimeImmutable('2026-01-01 00:00:00 Europe/Moscow'), 'mode' => FinancialReportSyncMode::MISSING]]);
        $this->claimForQueueCallback = fn (
            string $connectionId,
            string $companyId,
            MarketplaceType $marketplace,
            string $reportType,
            string $apiEndpoint,
            \DateTimeImmutable $businessDate,
            FinancialReportSyncMode $mode,
            bool $forceRefresh,
            \DateTimeImmutable $now,
        ): MarketplaceFinancialReportSyncStatus => $this->statusEntity(
            $businessDate->format('Y-m-d'),
            FinancialReportSyncStatus::QUEUED,
            null,
            $now->format(DATE_ATOM),
            456,
            'bbbbbbbb-bbbb-4bbb-8bbb-000000008888',
        );

        self::assertSame(1, $planner->planMissing(null, null, 1));
        self::assertSame('2026-01-01', $this->dispatchedMessages[0]->businessDate);
        self::assertSame(456, $this->dispatchedMessages[0]->rrdId);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-000000008888', $this->dispatchedMessages[0]->rawDocumentId);
    }

    public function testPlanMissingLimitIsAppliedPerConnection(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1'), $this->conn('c2', 'co2')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([]);
        $this->statuses->method('findRetryDueDays')->willReturnCallback(static fn (string $companyId): array => [['business_date' => new \DateTimeImmutable('2026-01-01 00:00:00 Europe/Moscow'), 'mode' => FinancialReportSyncMode::MISSING]]);

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

        self::assertSame(10, $planner->planMissing(null, null, 10));
        self::assertSame(['2026-01-03', '2026-01-04'], array_slice(array_map(static fn (SyncWbFinancialReportDayMessage $m): string => $m->businessDate, $this->dispatchedMessages), 0, 2));
    }

    public function testPlanDueRetryPreservesDailyMode(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findRetryDueDays')->willReturn([
            ['business_date' => new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'), 'mode' => FinancialReportSyncMode::DAILY],
        ]);

        self::assertSame(1, $planner->planDueRetry(null, null, 1));
        self::assertSame('daily', $this->dispatchedMessages[0]->mode);
    }

    public function testPlanDueRetryPreservesRefreshMode(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findRetryDueDays')->willReturn([
            ['business_date' => new \DateTimeImmutable('2026-05-20 00:00:00 Europe/Moscow'), 'mode' => FinancialReportSyncMode::REFRESH_14D],
        ]);

        self::assertSame(1, $planner->planDueRetry(null, null, 1));
        self::assertSame('refresh_14d', $this->dispatchedMessages[0]->mode);
    }

    public function testPlanEmptyRefreshForceRefreshesRetryableEmptyDaysOnly(): void
    {
        $planner = $this->planner();
        $this->connections->method('execute')->willReturn([$this->conn('c1', 'co1')]);
        $this->statuses->method('findStatusesForDateRange')->willReturn([
            $this->statusEntity('2026-05-17', FinancialReportSyncStatus::EMPTY, null, '2026-05-17T07:00:00+03:00', attempts: 5),
            $this->statusEntity('2026-05-18', FinancialReportSyncStatus::EMPTY, null, '2026-05-20T09:00:00+03:00', attempts: 2),
            $this->statusEntity('2026-05-19', FinancialReportSyncStatus::SUCCESS, null, '2026-05-19T09:00:00+03:00'),
            $this->statusEntity('2026-05-20', FinancialReportSyncStatus::EMPTY, null, '2026-05-18T09:00:00+03:00', attempts: 2),
        ]);
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
            self::assertSame(FinancialReportSyncMode::REFRESH_14D, $mode);
            self::assertTrue($forceRefresh);

            return $this->statusEntity(
                $businessDate->format('Y-m-d'),
                FinancialReportSyncStatus::QUEUED,
                null,
                $now->format(DATE_ATOM),
            );
        };

        self::assertSame(1, $planner->planEmptyRefresh(null, null, 1, new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-05-20'), 5));
        self::assertSame('2026-05-20', $this->dispatchedMessages[0]->businessDate);
        self::assertSame('refresh_14d', $this->dispatchedMessages[0]->mode);
        self::assertTrue($this->dispatchedMessages[0]->forceRefresh);
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

    private function statusEntity(
        string $day,
        FinancialReportSyncStatus $status,
        ?string $nextRetryAt,
        string $updatedAt,
        ?int $nextRrdId = null,
        ?string $stagingRawDocumentId = null,
        ?FinancialReportSyncMode $mode = FinancialReportSyncMode::REFRESH_14D,
        int $attempts = 0,
    ): MarketplaceFinancialReportSyncStatus&MockObject {
        $entity = $this->createMock(MarketplaceFinancialReportSyncStatus::class);
        $entity->method('getBusinessDate')->willReturn(new \DateTimeImmutable($day.' 00:00:00 Europe/Moscow'));
        $entity->method('getStatus')->willReturn($status);
        $entity->method('getNextRetryAt')->willReturn(null !== $nextRetryAt ? new \DateTimeImmutable($nextRetryAt) : null);
        $entity->method('getUpdatedAt')->willReturn(new \DateTimeImmutable($updatedAt));
        $entity->method('getNextRrdId')->willReturn($nextRrdId);
        $entity->method('getStagingRawDocumentId')->willReturn($stagingRawDocumentId);
        $entity->method('getMode')->willReturn($mode);
        $entity->method('getAttempts')->willReturn($attempts);

        return $entity;
    }

    /** @return array{id: string, connection_id: string, company_id: string} */
    private function conn(string $connectionId, string $companyId): array
    {
        return ['id' => $connectionId, 'connection_id' => $connectionId, 'company_id' => $companyId];
    }
}
