<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messenger;

use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Shared\Command\FailedQueueHealthCheckCommand;
use App\Shared\Infrastructure\Messenger\FailedTransportQuery;
use App\Shared\Messenger\FailedQueueHealthPolicy;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Stage 1.3 (R-03): гейт очереди failed на реальной таблице messenger_messages. Возраст считается в БД
 * от created_at, записанного Messenger в UTC; сообщения строятся тем же PhpSerializer, что пишет в таблицу воркер.
 */
final class FailedTransportQueryTest extends IntegrationTestCase
{
    private FailedTransportQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->executeStatement('DELETE FROM messenger_messages');
        $this->query = self::getContainer()->get(FailedTransportQuery::class);
    }

    public function testEmptyQueueSnapshot(): void
    {
        $snapshot = $this->query->snapshot();

        self::assertTrue($snapshot->isEmpty());
        self::assertNull($snapshot->oldestAgeSeconds);
        self::assertSame([], $snapshot->breakdown);
    }

    public function testCountsOnlyTheFailedQueueAndComputesAgeFromUtcCreatedAt(): void
    {
        $this->insert('failed', 7200, new ProcessDayReportMessage('co1', 'raw1'), 'async_pipeline');
        $this->insert('failed', 3 * 86400 + 4 * 3600, new ProcessDayReportMessage('co2', 'raw2'), 'async_pipeline');
        $this->insert('failed', 600, $this->syncMessage(), 'async_wb_finance');
        // Обычные очереди (не failed), в том числе очень старое сообщение, в глубину и возраст не входят.
        $this->insert('default', 30 * 86400, new ProcessDayReportMessage('co3', 'raw3'), 'async_sync');

        $snapshot = $this->query->snapshot();

        self::assertSame(3, $snapshot->count);
        self::assertEqualsWithDelta(3 * 86400 + 4 * 3600, (int) $snapshot->oldestAgeSeconds, 5, 'Возраст = UTC-сейчас − created_at самого старого, независимо от зоны PHP/МСК.');
        self::assertNotNull($snapshot->oldestCreatedAt);
        self::assertSame('UTC', $snapshot->oldestCreatedAt->getTimezone()->getName());
        self::assertEqualsWithDelta(time() - (3 * 86400 + 4 * 3600), $snapshot->oldestCreatedAt->getTimestamp(), 5);
    }

    public function testBreakdownAggregatesByMessageClassAndOriginalTransportWithoutUnserialize(): void
    {
        $this->insert('failed', 100, new ProcessDayReportMessage('co1', 'raw1'), 'async_pipeline');
        $this->insert('failed', 200, new ProcessDayReportMessage('co2', 'raw2'), 'async_pipeline');
        $this->insert('failed', 300, new ProcessDayReportMessage('co3', 'raw3'), 'async_pipeline');
        $this->insert('failed', 400, $this->syncMessage(), 'async_wb_finance');

        $snapshot = $this->query->snapshot();

        self::assertSame(4, $snapshot->breakdownSampled);
        self::assertSame([
            ['message_class' => ProcessDayReportMessage::class, 'original_transport' => 'async_pipeline', 'count' => 3],
            ['message_class' => SyncWbFinancialReportDayMessage::class, 'original_transport' => 'async_wb_finance', 'count' => 1],
        ], $snapshot->breakdown);
    }

    /** Возраст не зависит от часового пояса сессии БД: created_at — UTC, сравнение идёт в UTC. */
    public function testAgeDoesNotDependOnTheDatabaseSessionTimeZone(): void
    {
        $this->insert('failed', 5400, new ProcessDayReportMessage('co1', 'raw1'), 'async_pipeline');

        $this->connection->executeStatement("SET TIME ZONE 'Europe/Moscow'");
        $moscow = $this->query->snapshot()->oldestAgeSeconds;
        $this->connection->executeStatement("SET TIME ZONE 'Pacific/Auckland'");
        $auckland = $this->query->snapshot()->oldestAgeSeconds;
        $this->connection->executeStatement("SET TIME ZONE 'UTC'");

        self::assertEqualsWithDelta(5400, (int) $moscow, 5);
        self::assertEqualsWithDelta(5400, (int) $auckland, 5);
    }

    /** Проверка читает, но не меняет очередь: те же id, тот же счёт, delivered_at/available_at не тронуты. */
    public function testCheckDoesNotMutateTheFailureTransport(): void
    {
        $this->insert('failed', 90000, new ProcessDayReportMessage('co1', 'raw1'), 'async_pipeline');
        $this->insert('failed', 120, $this->syncMessage(), 'async_wb_finance');
        $before = $this->state();

        $this->query->snapshot();
        $this->command($this->createMock(LoggerInterface::class))->execute([]);

        self::assertSame($before, $this->state());
        self::assertCount(2, $before);
    }

    /** R-03: старое сообщение раньше не давало никакого сигнала; теперь aggregated error и exit 1, сообщение остаётся на месте. */
    public function testOldFailedMessageNowFailsTheGateWithOneAggregatedError(): void
    {
        $this->insert('failed', 30 * 3600, new ProcessDayReportMessage('co1', 'raw1'), 'async_pipeline');
        $this->insert('failed', 60, $this->syncMessage(), 'async_wb_finance');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Messenger failed queue unhealthy',
            self::callback(static fn (array $context): bool => 2 === $context['failed_count'] && '1d 6h' === $context['oldest_age']),
        );

        $tester = $this->command($logger);
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"));
    }

    public function testRecentFailureIsWarningAndEmptyQueueIsHealthy(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $empty = $this->command($logger);
        $empty->execute([]);
        self::assertSame(Command::SUCCESS, $empty->getStatusCode());
        self::assertStringContainsString('failed queue: OK', $empty->getDisplay());

        $this->insert('failed', 600, $this->syncMessage(), 'async_wb_finance');
        $recent = $this->command($logger);
        $recent->execute([]);
        self::assertSame(Command::SUCCESS, $recent->getStatusCode());
        self::assertStringContainsString('failed queue: WARNING', $recent->getDisplay());
    }

    // ------------------------------------------------------------------ helpers

    private function syncMessage(): SyncWbFinancialReportDayMessage
    {
        return new SyncWbFinancialReportDayMessage('co', 'conn', '2026-09-10', 'daily', false, 0, null);
    }

    private function insert(string $queue, int $ageSeconds, object $message, string $originalTransport): void
    {
        // Набор штампов как у настоящей строки failed: ошибка с цепочкой исключений, ретраи, id транспорта.
        $envelope = new Envelope($message, [
            new BusNameStamp('messenger.bus.default'),
            new TransportMessageIdStamp('1'),
            new DelayStamp(10000),
            new RedeliveryStamp(3),
            ErrorDetailsStamp::create(new \RuntimeException('boom', 0, new \LogicException('previous cause'))),
            new SentToFailureTransportStamp($originalTransport),
        ]);
        $encoded = (new PhpSerializer())->encode($envelope);

        $this->connection->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
             VALUES (:body, :headers, :queue, (NOW() AT TIME ZONE 'UTC') - make_interval(secs => :age), (NOW() AT TIME ZONE 'UTC') - make_interval(secs => :age))",
            [
                'body' => $encoded['body'],
                'headers' => json_encode($encoded['headers'] ?? []),
                'queue' => $queue,
                'age' => $ageSeconds,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function state(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, queue_name, created_at, available_at, delivered_at, md5(body) AS body_hash FROM messenger_messages ORDER BY id');
    }

    private function command(LoggerInterface $logger): CommandTester
    {
        return new CommandTester(new FailedQueueHealthCheckCommand($this->query, new FailedQueueHealthPolicy(86400, 10), $logger));
    }
}
