<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Performance;

use App\Shared\Infrastructure\Performance\PerformanceLogReader;
use App\Shared\Infrastructure\Performance\PerformanceReportBuilder;
use PHPUnit\Framework\TestCase;

final class PerformanceReportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/perf-report-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testPercentilesUseNearestRank(): void
    {
        $values = array_map('floatval', range(1, 100));

        self::assertSame(['p50' => 50.0, 'p95' => 95.0, 'p99' => 99.0, 'max' => 100.0], PerformanceReportBuilder::percentiles($values));
        self::assertSame(['p50' => 7.0, 'p95' => 7.0, 'p99' => 7.0, 'max' => 7.0], PerformanceReportBuilder::percentiles([7.0]));
        self::assertSame(['p50' => null, 'p95' => null, 'p99' => null, 'max' => null], PerformanceReportBuilder::percentiles([]));
    }

    public function testEmptyInputGivesAnEmptyButWellFormedReport(): void
    {
        $report = (new PerformanceReportBuilder())->build();

        self::assertSame(0, $report['events']);
        self::assertSame([], $report['stages']);
        self::assertSame([], $report['queues']);
        self::assertSame([], $report['handlers']);
        self::assertSame(0, $report['completeness']['messages_without_handler_event']);
    }

    public function testStagesQueuesHandlersAndCompletenessAreAggregatedSeparately(): void
    {
        $builder = new PerformanceReportBuilder();
        // Сообщение 1: полностью; сообщение 2: взято из очереди, завершения нет (воркер убит).
        $builder->add(['stage' => 'queue_wait', 'provider' => 'wb', 'duration_ms' => 100.0, 'transport' => 'async_wb_finance', 'trace' => '1-0', 'retry_count' => 0]);
        $builder->add(['stage' => 'api_fetch', 'provider' => 'wb', 'duration_ms' => 2000.0, 'calls' => 1, 'rows' => 1000, 'bytes' => 4_000_000, 'errors' => 0, 'memory_peak_bytes' => 50_000_000, 'outcome' => 'ok', 'trace' => '1-0']);
        $builder->add(['stage' => 'source_parse', 'provider' => 'wb', 'duration_ms' => 500.0, 'calls' => 1, 'rows' => 1000, 'bytes' => 4_000_000, 'errors' => 0, 'memory_peak_bytes' => 50_000_000, 'outcome' => 'ok', 'trace' => '1-0']);
        $builder->add(['stage' => 'storage_write', 'provider' => 'wb', 'backend' => 'postgres', 'duration_ms' => 300.0, 'calls' => 1, 'rows' => null, 'errors' => 0, 'memory_peak_bytes' => 50_000_000, 'outcome' => 'ok', 'trace' => '1-0']);
        $builder->add(['stage' => 'handler', 'provider' => 'wb', 'job' => 'SyncWbFinancialReportDayMessage', 'duration_ms' => 3000.0, 'memory_peak_bytes' => 60_000_000, 'memory_base_bytes' => 45_000_000, 'outcome' => 'ok', 'trace' => '1-0']);
        $builder->add(['stage' => 'queue_wait', 'provider' => 'wb', 'duration_ms' => null, 'transport' => 'failed', 'trace' => '2', 'retry_count' => 3, 'dropped_events' => 4]);

        $report = $builder->build();

        $fetch = $this->stage($report, 'api_fetch');
        self::assertSame(1000, $fetch['rows']);
        self::assertSame(500.0, $fetch['rows_per_sec']);
        self::assertSame(2_000_000.0, $fetch['bytes_per_sec']);
        self::assertSame(['p50' => 2000.0, 'p95' => 2000.0, 'p99' => 2000.0, 'max' => 2000.0], $fetch['duration_ms']);
        self::assertSame(50_000_000, $fetch['max_memory_peak_bytes']);

        $write = $this->stage($report, 'storage_write');
        self::assertSame('postgres', $write['backend']);
        self::assertNull($write['rows']);
        self::assertNull($write['rows_per_sec']);
        self::assertSame(1, $write['rows_missing_events']);

        $queues = array_column($report['queues'], null, 'transport');
        self::assertSame(100.0, $queues['async_wb_finance']['lag_ms']['p50']);
        self::assertSame(1, $queues['failed']['lag_unavailable']);
        self::assertSame(1, $queues['failed']['redelivered']);

        self::assertSame('SyncWbFinancialReportDayMessage', $report['handlers'][0]['job']);
        self::assertSame(60_000_000, $report['handlers'][0]['max_memory_peak_bytes']);
        self::assertSame(15_000_000, $report['handlers'][0]['max_memory_growth_bytes']);

        self::assertSame(1, $report['completeness']['messages_without_handler_event']);
        self::assertSame(0, $report['completeness']['handler_events_without_queue_wait']);
        self::assertSame(1, $report['completeness']['events_without_duration']);
        self::assertSame(4, $report['completeness']['dropped_events']);
    }

    public function testReaderReadsOnlyDaysInPeriodAndSkipsForeignAndBrokenLines(): void
    {
        $this->day('2026-10-01', [$this->line('api_fetch'), 'not json', json_encode(['message' => 'other', 'context' => []]), $this->line('handler', 2)]);
        $this->day('2026-10-02', [$this->line('handler')]);
        $this->day('2026-10-05', [$this->line('handler')]);

        $reader = new PerformanceLogReader($this->dir, 1024 * 1024, 1000);
        $events = iterator_to_array($reader->read(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-03')), false);

        self::assertCount(2, $events);
        self::assertSame(2, $reader->filesRead);
        self::assertSame(1, $reader->filesMissing);
        self::assertSame(1, $reader->invalidLines);
        // Чужое сообщение и событие неизвестной версии схемы.
        self::assertSame(2, $reader->foreignLines);
        self::assertFalse($reader->truncated);
    }

    public function testReaderStopsAtTheEventCap(): void
    {
        $this->day('2026-10-01', array_fill(0, 10, $this->line('handler')));

        $reader = new PerformanceLogReader($this->dir, 1024 * 1024, 3);
        $events = iterator_to_array($reader->read(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-01')), false);

        self::assertCount(3, $events);
        self::assertTrue($reader->truncated);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function stage(array $report, string $stage): array
    {
        foreach ($report['stages'] as $row) {
            if ($stage === $row['stage']) {
                return $row;
            }
        }

        self::fail('No stage '.$stage);
    }

    private function line(string $stage, int $version = 1): string
    {
        return (string) json_encode([
            'message' => 'perf',
            'context' => ['v' => $version, 'stage' => $stage, 'provider' => 'ozon', 'duration_ms' => 1.5, 'outcome' => 'ok'],
            'level_name' => 'INFO',
            'channel' => 'performance',
        ]);
    }

    /**
     * @param list<string|false> $lines
     */
    private function day(string $date, array $lines): void
    {
        file_put_contents($this->dir.'/performance-'.$date.'.jsonl', implode("\n", $lines)."\n");
    }
}
