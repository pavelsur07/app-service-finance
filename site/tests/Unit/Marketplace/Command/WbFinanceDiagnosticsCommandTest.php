<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\WbFinanceDiagnosticsCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WbFinanceDiagnosticsCommandTest extends TestCase
{
    public function testDiagnosticsCommandSmoke(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->method('fetchOne')->willReturn(0);

        $command = new WbFinanceDiagnosticsCommand(new DiagnosticsRedisFake(), new \stdClass(), new \stdClass(), $connection);
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
