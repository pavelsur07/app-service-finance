<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Command;

use App\Shared\Command\FailedQueueHealthCheckCommand;
use App\Shared\Infrastructure\Messenger\FailedTransportQuery;
use App\Shared\Messenger\FailedQueueHealthPolicy;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FailedQueueHealthCheckCommandTest extends TestCase
{
    private Connection&MockObject $connection;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testEmptyQueueIsHealthyAndSilent(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(['cnt' => 0, 'oldest_created_at' => null, 'oldest_age_seconds' => null]);
        $this->logger->expects(self::never())->method(self::anything());

        $tester = $this->runCheck();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('failed queue: OK', $tester->getDisplay());
    }

    public function testOneRecentMessageIsAWarningNotAnErrorAndExitsZero(): void
    {
        $this->seed(1, 1800);
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('warning')->with('Messenger failed queue has recent messages', self::anything());

        $tester = $this->runCheck();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('failed queue: WARNING', $tester->getDisplay());
    }

    /** R-03: сообщение пролежало дольше порога — раньше сигнала не было вообще, теперь aggregated error и exit 1. */
    public function testOldFailedMessageProducesOneAggregatedErrorWithCountAndAge(): void
    {
        $this->seed(15, 3 * 86400 + 4 * 3600, [
            ['message_class' => 'App\\\\Ingestion\\\\Message\\\\RunSyncChunkMessage\\', 'original_transport' => 'async_sync', 'cnt' => 11],
            ['message_class' => 'App\\\\Marketplace\\\\Message\\\\SyncWbFinancialReportDayMessage\\', 'original_transport' => 'async_wb_finance', 'cnt' => 4],
        ]);
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::once())->method('error')->with(
            'Messenger failed queue unhealthy',
            self::callback(static function (array $context): bool {
                return 15 === $context['failed_count']
                    && '3d 4h' === $context['oldest_age']
                    && 11 === $context['breakdown']['App\\Ingestion\\Message\\RunSyncChunkMessage [async_sync]']
                    && !\array_key_exists('body', $context) && !\array_key_exists('payload', $context);
            }),
        );

        $tester = $this->runCheck();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('oldest: 3d 4h', $tester->getDisplay());
        self::assertStringContainsString('breakdown coverage: 15 of 15 messages', $tester->getDisplay());
    }

    public function testHighDepthOfFreshMessagesIsAnError(): void
    {
        $this->seed(10, 120);
        $this->logger->expects(self::once())->method('error')->with('Messenger failed queue unhealthy', self::anything());

        self::assertSame(Command::FAILURE, $this->runCheck()->getStatusCode());
    }

    /** Недоступное хранилище не превращается в «очередь пуста». */
    public function testStorageFailureIsAnUnavailableErrorNotHealthy(): void
    {
        $this->connection->method('fetchAssociative')->willThrowException(new \RuntimeException('connection refused'));
        $this->logger->expects(self::once())->method('error')->with('Messenger failed queue check unavailable', self::arrayHasKey('exception'));

        $tester = $this->runCheck();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UNAVAILABLE', $tester->getDisplay());
        self::assertStringNotContainsString('failed queue: OK', $tester->getDisplay());
    }

    public function testBreakdownFailureDoesNotBreakTheMainCheck(): void
    {
        $this->connection->method('fetchAssociative')->willReturn(['cnt' => 3, 'oldest_created_at' => '2026-10-01 00:00:00', 'oldest_age_seconds' => 90000]);
        $this->connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('regex failure'));
        $this->logger->expects(self::once())->method('error')->with('Messenger failed queue unhealthy', self::callback(static fn (array $c): bool => [] === $c['breakdown'] && 0 === $c['breakdown_sampled']));

        self::assertSame(Command::FAILURE, $this->runCheck()->getStatusCode());
    }

    /** @param list<array<string, mixed>> $breakdownRows */
    private function seed(int $count, int $ageSeconds, array $breakdownRows = []): void
    {
        $this->connection->method('fetchAssociative')->willReturn([
            'cnt' => $count,
            'oldest_created_at' => '2026-10-01 00:00:00',
            'oldest_age_seconds' => $ageSeconds,
        ]);
        $this->connection->method('fetchAllAssociative')->willReturn($breakdownRows);
    }

    private function runCheck(): CommandTester
    {
        $command = new FailedQueueHealthCheckCommand(
            new FailedTransportQuery($this->connection),
            new FailedQueueHealthPolicy(86400, 10),
            $this->logger,
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
