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
        $this->db->method('fetchOne')->willReturnCallback(function (string $sql, array $params = []) use (&$dailySqlAssertions): mixed {
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
        $this->planner->expects(self::once())->method('planDueRetry')->with('company-a', 'conn-a', 1)->willReturn(1);
        $this->planner->expects(self::never())->method('planRefreshRecentDays');
        $this->planner->expects(self::never())->method('planMissing');

        self::assertSame(Command::SUCCESS, $this->execute($this->rateLimiter()));
    }

    public function testDueRetryCountRequiresRateLimitErrorClassAndFallsThroughToRefresh(): void
    {
        $this->connections->method('execute')->willReturn([$this->conn('conn-a', 'company-a')]);
        $dueRetrySqlAssertions = 0;
        $this->db->method('fetchOne')->willReturnCallback(function (string $sql, array $params = []) use (&$dueRetrySqlAssertions): mixed {
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
        self::assertSame(1, $dueRetrySqlAssertions);
    }

    private function execute(WbFinanceRateLimiter $rateLimiter): int
    {
        $command = new WbFinancialReportsOrchestrateCommand(
            $this->connections,
            $rateLimiter,
            new WbFinancialReportPeriodResolver(new MockClock('2026-05-21 00:00:00 Europe/Moscow')),
            $this->planner,
            $this->db,
            $this->logger,
        );

        return (new CommandTester($command))->execute([]);
    }

    /**
     * @param array<string, string|null> $dailyStatuses
     * @param array<string, int> $dueRetryCounts
     * @param array<string, int> $futureQueuedCounts
     */
    private function dbFetchOneCallback(array $dailyStatuses, array $dueRetryCounts = [], array $futureQueuedCounts = []): \Closure
    {
        return static function (string $sql, array $params = []) use ($dailyStatuses, $dueRetryCounts, $futureQueuedCounts): mixed {
            $key = ($params['companyId'] ?? '').':'.($params['connectionId'] ?? '');
            if (str_contains($sql, "status = 'queued'") && str_contains($sql, 'next_retry_at > NOW()')) {
                return $futureQueuedCounts[$key] ?? 0;
            }
            if (str_contains($sql, "status IN ('queued', 'failed')")) {
                return $dueRetryCounts[$key] ?? 0;
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
