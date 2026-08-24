<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Application\Service\WbFinanceRateLimiter;
use App\Marketplace\Application\Service\WbFinancialReportPeriodResolver;
use App\Marketplace\Command\WbFinanceDiagnosticsCommand;
use App\Marketplace\Infrastructure\Query\ActiveWbConnectionsQuery;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class WbFinanceDiagnosticsCommandTest extends TestCase
{
    public function testDiagnosticsCommandSmoke(): void
    {
        $connection = $this->sqliteConnection();
        $clock = new MockClock('2026-05-21 00:00:00 Europe/Moscow');
        $rateLimiter = new WbFinanceRateLimiter(
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()),
            $clock,
        );

        $command = new WbFinanceDiagnosticsCommand(
            new DiagnosticsRedisFake(),
            new \stdClass(),
            new \stdClass(),
            $connection,
            new ActiveWbConnectionsQuery($connection),
            $this->createMock(MarketplaceConnectionRepository::class),
            $rateLimiter,
            new WbFinancialReportPeriodResolver($clock),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        self::assertStringContainsString('Redis / limiter', $display);
        self::assertStringContainsString('Redis cooldown keys', $display);
        self::assertStringContainsString('Redis queue sizes', $display);
        self::assertStringContainsString('bucket_type', $display);
        self::assertStringContainsString('connection', $display);
        self::assertStringContainsString('WB sales_report sync_status counts', $display);
    }

    private function sqliteConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE marketplace_connections (
                id VARCHAR(36) PRIMARY KEY,
                company_id VARCHAR(36) NOT NULL,
                marketplace VARCHAR(32) NOT NULL,
                is_active BOOLEAN NOT NULL,
                connection_type VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE marketplace_financial_report_sync_statuses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_id VARCHAR(36),
                connection_id VARCHAR(36),
                marketplace VARCHAR(32),
                report_type VARCHAR(32),
                business_date DATE,
                mode VARCHAR(32),
                status VARCHAR(32),
                next_retry_at DATETIME,
                last_error_status_code INTEGER,
                last_error_class VARCHAR(255),
                updated_at DATETIME,
                raw_document_id VARCHAR(36)
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE marketplace_financial_report_sync_errors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_id VARCHAR(36),
                connection_id VARCHAR(36),
                status_code INTEGER,
                created_at DATETIME
            )',
        );
        $connection->executeStatement(
            'CREATE TABLE marketplace_raw_documents (
                id VARCHAR(36) PRIMARY KEY,
                marketplace VARCHAR(32),
                document_type VARCHAR(32),
                processing_status VARCHAR(32),
                processed_at DATETIME,
                synced_at DATETIME,
                records_count INTEGER
            )',
        );

        return $connection;
    }
}

final class DiagnosticsRedisFake
{
    /** @return array{0: string, 1: list<string>} */
    public function scan(string $cursor, array $options): array
    {
        return ['0', ['wb_finance:sales_reports:cooldown:connection:6eada2b7-b453-4c33-a92a-e7dce52e291c']];
    }

    public function get(string $key): string
    {
        return '1767225600';
    }

    public function ttl(string $key): int
    {
        return 60;
    }

    public function xlen(string $key): int
    {
        return 1;
    }

    public function zcard(string $key): int
    {
        return 2;
    }
}
