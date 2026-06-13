<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinanceCooldownStorageInterface;
use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Command\WbFinancialReportsOrchestrateCommand;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinancialReportsOrchestrateCommandTest extends TestCase
{
    private ActiveWbConnectionsQuery&MockObject $connections;
    private WbFinancialReportSyncPlannerInterface&MockObject $planner;
    private Connection&MockObject $db;
    private LoggerInterface&MockObject $logger;
    private InMemoryCooldownStorage $cooldownStorage;

    protected function setUp(): void
    {
        $this->connections = $this->createMock(ActiveWbConnectionsQuery::class);
        $this->planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $this->db = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cooldownStorage = new InMemoryCooldownStorage();
    }

    public function testGlobalCooldownDoesNotBlockConnectionPlanning(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $rateLimiter = $this->rateLimiter();
        $rateLimiter->setSalesReportsCooldownUntil('global', new \DateTimeImmutable('2026-05-21T18:00:00+03:00'));
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(['company-a:conn-a' => null]));
        $this->logger->expects(self::once())->method('warning');
        $this->planner->expects(self::once())->method('planDaily')->with('company-a', 'conn-a', false)->willReturn(1);

        self::assertSame(Command::SUCCESS, $this->execute($rateLimiter));
    }

    public function testConnectionCooldownBlocksOnlyThatConnection(): void
    {
        $this->connections->method('execute')->willReturn([
            $this->conn('conn-a', 'company-a'),
            $this->conn('conn-b', 'company-b'),
        ]);
        $rateLimiter = $this->rateLimiter();
        $rateLimiter->setSalesReportsCooldownUntil('connection:conn-a', new \DateTimeImmutable('2026-05-21T18:00:00+03:00'));
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback([
            'company-a:conn-a' => null,
            'company-b:conn-b' => null,
        ]));
        $this->planner->expects(self::once())->method('planDaily')->with('company-b', 'conn-b', false)->willReturn(1);

        self::assertSame(Command::SUCCESS, $this->execute($rateLimiter));
    }

    public function testDailyStatusIsReadOnlyForDailyMode(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $dailySqlAssertions = 0;
        $this->db->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []) use (&$dailySqlAssertions): mixed {
            if (str_contains($sql, 'AND business_date = :businessDate')) {
                ++$dailySqlAssertions;
                self::assertStringContainsString("AND mode = 'daily'", $sql);

                return 'success';
            }

            return 0;
        });
        $this->planner->expects(self::never())->method('planDaily');
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(1);

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
        self::assertSame(1, $dailySqlAssertions);
    }

    public function testOneTaskPerConnectionKeepsDueRetryAheadOfRefresh(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a' => 1],
        ));
        $this->planner->expects(self::once())->method('planDueRetry')->with(
            'company-a',
            'conn-a',
            1,
            self::isInstanceOf(\DateTimeImmutable::class),
            self::isInstanceOf(\DateTimeImmutable::class),
        )->willReturn(1);
        $this->planner->expects(self::never())->method('planRefreshRecentDays');
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
    }

    public function testDueRetryCountRequiresRateLimitErrorClassAndFallsThroughToRefresh(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $dueRetrySqlAssertions = 0;
        $this->db->method('fetchOne')->willReturnCallback(static function (string $sql, array $params = []) use (&$dueRetrySqlAssertions): mixed {
            if (str_contains($sql, 'AND business_date = :businessDate')) {
                return 'success';
            }
            if (str_contains($sql, "status = 'queued'") && str_contains($sql, 'next_retry_at > NOW()')) {
                return 0;
            }
            if (str_contains($sql, "status IN ('queued', 'failed')")) {
                ++$dueRetrySqlAssertions;
                self::assertStringContainsString('last_error_class = :rateLimitErrorClass', $sql);
                self::assertSame('App\Marketplace\Exception\MarketplaceRateLimitException', $params['rateLimitErrorClass'] ?? null);

                return 0;
            }

            return 0;
        });
        $this->planner->expects(self::never())->method('planDueRetry');
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(1);

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
        self::assertSame(2, $dueRetrySqlAssertions);
    }

    public function testOldDueRetryOutsideDefaultWindowFallsThroughToRefresh(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 0, 'company-a:conn-a:2026-01-01:2026-05-06' => 1],
        ));
        $this->planner->expects(self::never())->method('planDueRetry');
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(1);
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
    }

    public function testRecentDueRetryInsideWindowRunsBeforeRefresh(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 1],
        ));
        $this->planner->expects(self::once())->method('planDueRetry')->with(
            'company-a',
            'conn-a',
            1,
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-05-07' === $date->format('Y-m-d')),
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-05-20' === $date->format('Y-m-d')),
        )->willReturn(1);
        $this->planner->expects(self::never())->method('planRefreshRecentDays');
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
    }

    public function testIncludeHistoricalRetryAllowsOnlyConfiguredHistoricalBatch(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 0, 'company-a:conn-a:2026-01-01:2026-05-06' => 1],
        ));
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(0);
        $this->planner->expects(self::once())->method('planDueRetry')->with(
            'company-a',
            'conn-a',
            1,
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-01-01' === $date->format('Y-m-d')),
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-05-06' === $date->format('Y-m-d')),
        )->willReturn(1);
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter(), ['--include-historical-retry' => true]));
    }

    public function testRefreshDaysBackDoesNotEnableHistoricalRetry(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 0, 'company-a:conn-a:2026-01-01:2026-05-06' => 1],
        ));
        $this->planner->expects(self::never())->method('planDueRetry');
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(1);
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter(), ['--refresh-days-back' => '2']));
    }

    public function testRecentMissingIsNotPlannedWhenRecentDueRetryCannotBeClaimed(): void
    {
        $tester = null;
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 1],
            [],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 13],
        ));
        $this->planner->expects(self::once())->method('planDueRetry')->willReturn(0);
        $this->planner->expects(self::never())->method('planRefreshRecentDays');
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter(), [], $tester));
        self::assertStringContainsString('recent due retry skipped by claim', $tester->getDisplay());
        self::assertStringContainsString('Dispatched 0 task(s).', $tester->getDisplay());
    }

    public function testHistoricalMissingIsNotPlannedWhenHistoricalDueRetryCannotBeClaimed(): void
    {
        $tester = null;
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 0, 'company-a:conn-a:2026-01-01:2026-05-06' => 1],
            [],
            ['company-a:conn-a:2026-01-01:2026-05-06' => 0],
        ));
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(0);
        $this->planner->expects(self::once())->method('planDueRetry')->with(
            'company-a',
            'conn-a',
            1,
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-01-01' === $date->format('Y-m-d')),
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-05-06' === $date->format('Y-m-d')),
        )->willReturn(0);
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter(), ['--include-historical-retry' => true], $tester));
        self::assertStringContainsString('historical due retry skipped by claim', $tester->getDisplay());
        self::assertStringContainsString('Dispatched 0 task(s).', $tester->getDisplay());
    }

    public function testRecentMissingIsPlannedWhenNoRecentDueRetryExists(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $this->db->method('fetchOne')->willReturnCallback($this->dbFetchOneCallback(
            ['company-a:conn-a' => 'success'],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 0],
            [],
            ['company-a:conn-a:2026-05-07:2026-05-20' => 13],
        ));
        $this->planner->expects(self::never())->method('planDueRetry');
        $this->planner->expects(self::once())->method('planRefreshRecentDays')->with('company-a', 'conn-a', 2, 1)->willReturn(0);
        $this->planner->expects(self::once())->method('planMissing')->with(
            'company-a',
            'conn-a',
            1,
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-05-07' === $date->format('Y-m-d')),
            self::callback(static fn (\DateTimeImmutable $date): bool => '2026-05-20' === $date->format('Y-m-d')),
        )->willReturn(1);

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
    }

    private function execute(WbFinanceRateLimiter $rateLimiter, array $input = [], ?CommandTester &$tester = null): int
    {
        $command = new WbFinancialReportsOrchestrateCommand(
            $this->connections,
            $rateLimiter,
            new WbFinancialReportPeriodResolver(new MockClock('2026-05-21 00:00:00 Europe/Moscow')),
            $this->planner,
            $this->db,
            $this->logger,
        );

        $tester = new CommandTester($command);

        return $tester->execute($input);
    }

    /**
     * @param array<string, string|null> $dailyStatuses
     * @param array<string, int> $dueRetryCounts keyed by company:connection or company:connection:from:to
     * @param array<string, int> $futureQueuedCounts
     * @param array<string, int> $knownDayCounts keyed by company:connection or company:connection:from:to
     */
    private function dbFetchOneCallback(array $dailyStatuses, array $dueRetryCounts = [], array $futureQueuedCounts = [], array $knownDayCounts = []): \Closure
    {
        return static function (string $sql, array $params = []) use ($dailyStatuses, $dueRetryCounts, $futureQueuedCounts, $knownDayCounts): mixed {
            $key = ($params['companyId'] ?? '').':'.($params['connectionId'] ?? '');
            $rangeKey = $key.':'.($params['fromDate'] ?? '').':'.($params['toDate'] ?? '');
            if (str_contains($sql, 'COUNT(DISTINCT business_date)')) {
                if (array_key_exists($rangeKey, $knownDayCounts) || array_key_exists($key, $knownDayCounts)) {
                    return $knownDayCounts[$rangeKey] ?? $knownDayCounts[$key];
                }

                $from = new \DateTimeImmutable((string) $params['fromDate']);
                $to = new \DateTimeImmutable((string) $params['toDate']);

                return $from->diff($to)->days + 1;
            }
            if (str_contains($sql, "status = 'queued'") && str_contains($sql, 'next_retry_at > NOW()')) {
                return $futureQueuedCounts[$rangeKey] ?? $futureQueuedCounts[$key] ?? 0;
            }
            if (str_contains($sql, "status IN ('queued', 'failed')")) {
                return $dueRetryCounts[$rangeKey] ?? $dueRetryCounts[$key] ?? 0;
            }
            if (str_contains($sql, 'AND business_date = :businessDate')) {
                return $dailyStatuses[$key] ?? null;
            }

            return 0;
        };
    }

    private function rateLimiter(): WbFinanceRateLimiter
    {
        return new WbFinanceRateLimiter(
            new RateLimiterFactory([
                'id' => 'wb_finance_orchestrator_test',
                'policy' => 'fixed_window',
                'limit' => 1,
                'interval' => '1 second',
            ], new InMemoryStorage()),
            new MockClock('2026-05-21T10:00:00+03:00'),
            null,
            $this->cooldownStorage,
        );
    }

    /** @return array{id: string, connection_id: string, company_id: string} */
    private function conn(string $connectionId, string $companyId): array
    {
        return ['id' => $connectionId, 'connection_id' => $connectionId, 'company_id' => $companyId];
    }
}

final class InMemoryCooldownStorage implements WbFinanceCooldownStorageInterface
{
    /** @var array<string, int> */
    private array $timestamps = [];

    public function getUntilTimestamp(string $key): ?int
    {
        return $this->timestamps[$key] ?? null;
    }

    public function setUntilTimestamp(string $key, int $untilTimestamp, int $ttlSeconds): void
    {
        $this->timestamps[$key] = $untilTimestamp;
    }
}
