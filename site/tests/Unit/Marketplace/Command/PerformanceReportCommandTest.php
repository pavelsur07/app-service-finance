<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Command;

use App\Marketplace\Command\PerformanceReportCommand;
use App\Shared\Infrastructure\Messenger\FailedTransportQuery;
use App\Shared\Infrastructure\Messenger\MessengerQueueSnapshotQuery;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PerformanceReportCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/perf-cmd-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testNoDataIsReportedExplicitly(): void
    {
        $tester = $this->tester();

        $code = $tester->execute(['--from' => '2026-10-01', '--to' => '2026-10-02', '--skip-queues' => true]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('Нет данных за период', $tester->getDisplay());
    }

    public function testJsonReportWithData(): void
    {
        $lines = [
            ['stage' => 'queue_wait', 'provider' => 'ozon', 'duration_ms' => 250.0, 'transport' => 'async_sync', 'trace' => '1-0', 'lag_source' => 'redis_stream_id'],
            ['stage' => 'api_fetch', 'provider' => 'ozon', 'duration_ms' => 1200.0, 'calls' => 3, 'rows' => 900, 'bytes' => 300000, 'errors' => 1, 'memory_peak_bytes' => 40000000, 'outcome' => 'ok', 'trace' => '1-0'],
            ['stage' => 'handler', 'provider' => 'ozon', 'job' => 'SyncOzonAccrualByDayMessage', 'duration_ms' => 1500.0, 'memory_peak_bytes' => 41000000, 'outcome' => 'ok', 'trace' => '1-0'],
        ];
        file_put_contents($this->dir.'/performance-2026-10-01.jsonl', implode("\n", array_map(
            static fn (array $c): string => (string) json_encode(['message' => 'perf', 'context' => ['v' => 1] + $c]),
            $lines,
        ))."\n");
        $tester = $this->tester();

        $code = $tester->execute(['--from' => '2026-10-01', '--to' => '2026-10-01', '--skip-queues' => true, '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $code);
        // JSON теряет дробную часть у целых float — сравнение по значению.
        $report = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(3, $report['events']);
        self::assertSame(900, $report['stages'][0]['rows']);
        self::assertEquals(750.0, $report['stages'][0]['rows_per_sec']);
        self::assertSame(1, $report['stages'][0]['errors']);
        self::assertEquals(250.0, $report['queues'][0]['lag_ms']['p95']);
        self::assertEquals(1500.0, $report['handlers'][0]['duration_ms']['p99']);
        self::assertSame(0, $report['completeness']['messages_without_handler_event']);
        self::assertArrayNotHasKey('queue_snapshot', $report);
    }

    public function testMarkdownReportRendersTables(): void
    {
        file_put_contents($this->dir.'/performance-2026-10-01.jsonl', json_encode(['message' => 'perf', 'context' => ['v' => 1, 'stage' => 'handler', 'provider' => 'wb', 'job' => 'SyncWbFinancialReportDayMessage', 'duration_ms' => 10.0, 'outcome' => 'ok']])."\n");
        $tester = $this->tester();

        $tester->execute(['--from' => '2026-10-01', '--to' => '2026-10-01', '--skip-queues' => true]);

        self::assertStringContainsString('| SyncWbFinancialReportDayMessage | wb | 1 | 10.0 |', $tester->getDisplay());
    }

    public function testPeriodIsBounded(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-01-01', '--to' => '2026-03-01', '--skip-queues' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-10-05', '--to' => '2026-10-01', '--skip-queues' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--from' => '01.10.2026', '--skip-queues' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'xml', '--skip-queues' => true]));
    }

    public function testQueueSnapshotFailureDoesNotFailTheReport(): void
    {
        $redis = $this->createMock(Client::class);
        $redis->method('executeRaw')->willThrowException(new \RuntimeException('connection refused'));
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('db down'));
        $tester = $this->tester($redis, $connection);

        $code = $tester->execute(['--format' => 'json']);

        self::assertSame(Command::SUCCESS, $code);
        $report = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('error: RuntimeException', $report['queue_snapshot']['redis'][0]['status']);
        self::assertSame('unavailable: RuntimeException', $report['queue_snapshot']['failed']);
    }

    private function tester(?Client $redis = null, ?Connection $connection = null): CommandTester
    {
        $command = new PerformanceReportCommand(
            $this->dir,
            new MessengerQueueSnapshotQuery($redis ?? $this->createMock(Client::class), 'redis://redis:6379', ['async_sync' => 'redis://redis:6379/messages_sync']),
            new FailedTransportQuery($connection ?? $this->createMock(Connection::class)),
            new PerformanceRecorder(),
        );

        return new CommandTester($command);
    }
}
