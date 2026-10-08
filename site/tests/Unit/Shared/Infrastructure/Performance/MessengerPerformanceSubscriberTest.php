<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Performance;

use App\Marketplace\Message\CloseMonthStageMessage;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Shared\Infrastructure\Performance\MessengerPerformanceSubscriber;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class MessengerPerformanceSubscriberTest extends TestCase
{
    private const COMPANY = '0192a8f0-1111-7aaa-8bbb-123456789abc';

    public function testRedisStreamIdGivesEnqueueLagAndHandledMessageGivesHandlerEvent(): void
    {
        $handler = new TestHandler();
        $subscriber = new MessengerPerformanceSubscriber($this->recorder($handler, true));
        $enqueuedMs = (int) floor(microtime(true) * 1000) - 1500;
        $envelope = new Envelope(
            new ProcessDayReportMessage(companyId: self::COMPANY, rawDocumentId: '0192a8f0-2222-7aaa-8bbb-123456789abc'),
            [new TransportMessageIdStamp($enqueuedMs.'-0')],
        );

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async_pipeline'));
        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async_pipeline'));

        [$wait, $done] = array_map(static fn ($r): array => $r->context, $handler->getRecords());
        self::assertSame('queue_wait', $wait['stage']);
        self::assertSame('redis_stream_id', $wait['lag_source']);
        self::assertGreaterThanOrEqual(1500.0, $wait['duration_ms']);
        self::assertLessThan(60_000.0, $wait['duration_ms']);
        self::assertSame('ProcessDayReportMessage', $wait['job']);
        self::assertSame(self::COMPANY, $wait['company_id']);
        self::assertSame('async_pipeline', $wait['transport']);
        self::assertSame(0, $wait['retry_count']);

        self::assertSame('handler', $done['stage']);
        self::assertSame('ok', $done['outcome']);
        self::assertSame($enqueuedMs.'-0', $done['trace']);
    }

    public function testFailureWithRetryAndRetryCountAreRecorded(): void
    {
        $handler = new TestHandler();
        $subscriber = new MessengerPerformanceSubscriber($this->recorder($handler, true));
        $envelope = new Envelope($this->wbMessage(), [new TransportMessageIdStamp('1728380000000-3'), new RedeliveryStamp(2)]);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async_wb_finance'));
        $failed = new WorkerMessageFailedEvent($envelope, 'async_wb_finance', new \RuntimeException('429'));
        $failed->setForRetry();
        $subscriber->onFailed($failed);

        [$wait, $done] = array_map(static fn ($r): array => $r->context, $handler->getRecords());
        self::assertSame('wb', $wait['provider']);
        self::assertSame(2, $wait['retry_count']);
        self::assertSame('retry', $done['outcome']);
    }

    public function testFinalFailureIsAnError(): void
    {
        $handler = new TestHandler();
        $subscriber = new MessengerPerformanceSubscriber($this->recorder($handler, true));
        $envelope = new Envelope($this->wbMessage(), [new TransportMessageIdStamp('1728380000000-3')]);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async_wb_finance'));
        $subscriber->onFailed(new WorkerMessageFailedEvent($envelope, 'async_wb_finance', new \RuntimeException('auth')));

        self::assertSame('error', $handler->getRecords()[1]->context['outcome']);
    }

    public function testNonRedisMessageIdHasNoLag(): void
    {
        $handler = new TestHandler();
        $subscriber = new MessengerPerformanceSubscriber($this->recorder($handler, true));
        $envelope = new Envelope($this->wbMessage(), [new TransportMessageIdStamp(42)]);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'failed'));

        $wait = $handler->getRecords()[0]->context;
        self::assertNull($wait['duration_ms']);
        self::assertSame('unavailable', $wait['lag_source']);
    }

    public function testDisabledFlagWritesNothing(): void
    {
        $handler = new TestHandler();
        $subscriber = new MessengerPerformanceSubscriber($this->recorder($handler, false));
        $envelope = new Envelope($this->wbMessage(), [new TransportMessageIdStamp('1728380000000-3')]);

        $subscriber->onReceived(new WorkerMessageReceivedEvent($envelope, 'async_wb_finance'));
        $subscriber->onHandled(new WorkerMessageHandledEvent($envelope, 'async_wb_finance'));

        self::assertSame([], $handler->getRecords());
    }

    public function testMessageWhoseCompanyGetterThrowsDoesNotBreakTheWorker(): void
    {
        $handler = new TestHandler();
        $recorder = $this->recorder($handler, true);
        $subscriber = new MessengerPerformanceSubscriber($recorder);
        $message = new class {
            public function getCompanyId(): string
            {
                throw new \LogicException('broken message');
            }
        };

        $errorLog = tempnam(sys_get_temp_dir(), 'perf-errlog');
        $previous = ini_set('error_log', (string) $errorLog);
        try {
            $subscriber->onReceived(new WorkerMessageReceivedEvent(new Envelope($message), 'async_sync'));
        } finally {
            ini_set('error_log', false === $previous ? '' : $previous);
            @unlink((string) $errorLog);
        }

        self::assertSame(1, $recorder->failedWrites());
    }

    public function testProviderComesFromMarketplaceFieldOrClassName(): void
    {
        self::assertSame('wb', MessengerPerformanceSubscriber::providerOf(new CloseMonthStageMessage(self::COMPANY, 'wildberries', 2026, 9, 'sales', self::COMPANY)));
        self::assertSame('ozon', MessengerPerformanceSubscriber::providerOf(new CloseMonthStageMessage(self::COMPANY, 'ozon', 2026, 9, 'sales', self::COMPANY)));
        self::assertSame('wb', MessengerPerformanceSubscriber::providerOf($this->wbMessage()));
        self::assertSame('none', MessengerPerformanceSubscriber::providerOf(new \stdClass()));
    }

    private function recorder(TestHandler $handler, bool $enabled): PerformanceRecorder
    {
        return new PerformanceRecorder(new Logger('performance', [$handler]), $enabled);
    }

    private function wbMessage(): SyncWbFinancialReportDayMessage
    {
        return new SyncWbFinancialReportDayMessage(self::COMPANY, '0192a8f0-3333-7aaa-8bbb-123456789abc', '2026-10-01', 'daily', false);
    }
}
