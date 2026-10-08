<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Performance;

use App\Shared\Infrastructure\Performance\PerformanceOutcome;
use App\Shared\Infrastructure\Performance\PerformanceProbe;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Shared\Infrastructure\Performance\PerformanceStage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class PerformanceRecorderTest extends TestCase
{
    private const COMPANY = '0192a8f0-1111-7aaa-8bbb-123456789abc';

    /** Белый список полей события: ничего сверх — ни сумм, ни тел, ни путей. */
    private const ALLOWED_FIELDS = [
        'v', 'stage', 'provider', 'backend', 'duration_ms', 'calls', 'rows', 'bytes', 'errors',
        'memory_peak_bytes', 'memory_base_bytes', 'outcome', 'retry_count', 'company_id', 'job', 'transport', 'trace',
        'lag_source', 'dropped_events',
    ];

    public function testDisabledRecorderMeasuresNothingAndPassesResultThrough(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), new NullLogger(), false);

        $recorder->beginScope('SyncOzonAccrualByDayMessage', 'ozon', self::COMPANY);
        self::assertNull($recorder->start());
        self::assertFalse($recorder->hasScope());

        $payload = new \stdClass();
        $result = $recorder->measure(PerformanceStage::ApiFetch, static fn (PerformanceProbe $p): \stdClass => $payload);
        $recorder->recordQueueWait(10.0, 'redis_stream_id');
        $recorder->endScope(PerformanceOutcome::Ok);

        self::assertSame($payload, $result);
        self::assertSame([], $handler->getRecords());
    }

    public function testOutsideScopeNothingIsMeasuredEvenWhenEnabled(): void
    {
        $handler = new TestHandler();
        $recorder = $this->enabled($handler);

        self::assertNull($recorder->start());
        $recorder->add(PerformanceStage::StorageRead, hrtime(true), rows: 1);
        $recorder->endScope(PerformanceOutcome::Ok);

        self::assertSame([], $handler->getRecords());
    }

    public function testScopeAggregatesStagesIntoOneEventPerStageAndAHandlerEvent(): void
    {
        $handler = new TestHandler();
        $recorder = $this->enabled($handler);

        $recorder->beginScope('SyncWbFinancialReportDayMessage', 'wb', self::COMPANY, 'async_wb_finance', '1728380000000-0', 2);
        $recorder->measure(PerformanceStage::ApiFetch, static fn (PerformanceProbe $p) => $p->rows(100)->bytes(2048));
        $recorder->measure(PerformanceStage::ApiFetch, static fn (PerformanceProbe $p) => $p->rows(50)->bytes(1024));
        $recorder->add(PerformanceStage::StorageWrite, $recorder->start(), backend: 'postgres');
        $recorder->endScope(PerformanceOutcome::Ok);

        $events = $this->events($handler);
        self::assertCount(3, $events);

        [$fetch, $write, $done] = $events;
        self::assertSame('api_fetch', $fetch['stage']);
        self::assertSame(2, $fetch['calls']);
        self::assertSame(150, $fetch['rows']);
        self::assertSame(3072, $fetch['bytes']);
        self::assertSame(0, $fetch['errors']);
        self::assertSame('wb', $fetch['provider']);
        self::assertSame(self::COMPANY, $fetch['company_id']);
        self::assertSame('async_wb_finance', $fetch['transport']);
        self::assertSame('1728380000000-0', $fetch['trace']);
        self::assertSame(2, $fetch['retry_count']);
        self::assertIsInt($fetch['memory_peak_bytes']);

        self::assertSame('storage_write', $write['stage']);
        self::assertSame('postgres', $write['backend']);
        self::assertNull($write['rows']);

        self::assertSame('handler', $done['stage']);
        self::assertSame('ok', $done['outcome']);
        self::assertSame(0, $done['errors']);
        self::assertGreaterThanOrEqual(0.0, $done['duration_ms']);
        self::assertGreaterThan(0, $done['memory_base_bytes']);
        self::assertGreaterThanOrEqual($done['memory_base_bytes'], $done['memory_peak_bytes']);

        foreach ($events as $event) {
            self::assertSame([], array_diff(array_keys($event), self::ALLOWED_FIELDS));
            self::assertSame(PerformanceRecorder::SCHEMA_VERSION, $event['v']);
        }
    }

    public function testMeasureRethrowsTheOriginalExceptionAndCountsTheError(): void
    {
        $handler = new TestHandler();
        $recorder = $this->enabled($handler);
        $recorder->beginScope('SyncOzonRealizationMessage', 'ozon');
        $original = new \RuntimeException('ozon 503');

        $caught = null;
        try {
            $recorder->measure(PerformanceStage::ApiFetch, static function () use ($original): never {
                throw $original;
            });
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertSame($original, $caught);

        $recorder->endScope(PerformanceOutcome::Retry);
        [$fetch, $done] = $this->events($handler);

        self::assertSame(1, $fetch['errors']);
        self::assertSame('retry', $fetch['outcome']);
        self::assertSame('retry', $done['outcome']);
        self::assertSame(1, $done['errors']);
    }

    public function testFailingLogWriterNeverBreaksTheBusinessCallAndWarnsOnce(): void
    {
        $fallback = new TestHandler();
        $recorder = new PerformanceRecorder(new class extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                throw new \RuntimeException('disk full');
            }
        }, new Logger('app', [$fallback]), true);

        $recorder->beginScope('ProcessDayReportMessage');
        $payload = new \stdClass();
        $result = $recorder->measure(PerformanceStage::StorageRead, static fn (PerformanceProbe $p): \stdClass => $payload);
        $recorder->recordQueueWait(5.0, 'redis_stream_id');
        $recorder->endScope(PerformanceOutcome::Ok);
        $recorder->beginScope('ProcessDayReportMessage');
        $recorder->endScope(PerformanceOutcome::Ok);

        self::assertSame($payload, $result);
        self::assertGreaterThanOrEqual(3, $recorder->failedWrites());
        self::assertCount(1, $fallback->getRecords());
        self::assertSame('WARNING', $fallback->getRecords()[0]->level->getName());
        self::assertSame(['exception_class' => \RuntimeException::class], $fallback->getRecords()[0]->context);
    }

    public function testRateLimitDropsExcessEventsAndReportsTheCount(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), new NullLogger(), true, 2);

        $recorder->beginScope('A');
        $recorder->recordQueueWait(1.0, 'redis_stream_id');
        $recorder->endScope(PerformanceOutcome::Ok);
        $recorder->beginScope('B');
        $recorder->recordQueueWait(1.0, 'redis_stream_id');
        $recorder->endScope(PerformanceOutcome::Ok);

        self::assertCount(2, $handler->getRecords());
    }

    public function testUntrustedLabelsAreNormalisedToBoundedValues(): void
    {
        $handler = new TestHandler();
        $recorder = $this->enabled($handler);

        $recorder->beginScope('job with spaces & secrets?token=abc', 'yandex', 'not-a-uuid', "evil\ntransport", 'trace=abc');
        $recorder->endScope(PerformanceOutcome::Ok);

        [$event] = $this->events($handler);
        self::assertSame('other', $event['job']);
        self::assertSame('none', $event['provider']);
        self::assertNull($event['company_id']);
        self::assertNull($event['transport']);
        self::assertNull($event['trace']);
    }

    public function testUnfinishedScopeIsClosedAsUnknownWhenTheNextOneBegins(): void
    {
        $handler = new TestHandler();
        $recorder = $this->enabled($handler);

        $recorder->beginScope('First');
        $recorder->beginScope('Second');
        $recorder->endScope(PerformanceOutcome::Ok);

        $events = $this->events($handler);
        self::assertSame(['First', 'unknown'], [$events[0]['job'], $events[0]['outcome']]);
        self::assertSame(['Second', 'ok'], [$events[1]['job'], $events[1]['outcome']]);
    }

    public function testProviderSetAfterLoadingTheDocumentAppliesToLaterStages(): void
    {
        $handler = new TestHandler();
        $recorder = $this->enabled($handler);

        $recorder->beginScope('ProcessRawDocumentStepMessage');
        $recorder->setProvider('ozon');
        $recorder->addElapsed(PerformanceStage::SourceNormalize, 2_000_000, rows: 10);
        $recorder->endScope(PerformanceOutcome::Ok);

        [$normalize, $done] = $this->events($handler);
        self::assertSame('ozon', $normalize['provider']);
        self::assertSame(2.0, $normalize['duration_ms']);
        self::assertSame('ozon', $done['provider']);
    }

    private function enabled(TestHandler $handler): PerformanceRecorder
    {
        return new PerformanceRecorder(new Logger('performance', [$handler]), new NullLogger(), true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(TestHandler $handler): array
    {
        return array_values(array_map(static function ($record): array {
            self::assertSame(PerformanceRecorder::LOG_MESSAGE, $record->message);
            self::assertSame('INFO', $record->level->getName());

            return $record->context;
        }, $handler->getRecords()));
    }
}
