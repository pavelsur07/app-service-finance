<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\MarketplaceAds\Command\AdBatchSchedulerCommand;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Exception\OzonRateLimitException;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonPermanentApiException;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit-тесты {@see AdBatchSchedulerCommand}: проверяют сетки обработки ошибок
 * (happy / 429 / permanent / transient / empty) без реальной БД. Транзакционная
 * обвязка, state-переходы и вызов `postStatistics` — через моки.
 *
 * Цель: дёшево зафиксировать контракты catch-веток. Концевая проверка
 * «реально сохранилось в БД» — в интеграционном тесте.
 */
final class AdBatchSchedulerCommandTest extends TestCase
{
    /** @var OzonAdClient&MockObject */
    private OzonAdClient $ozonClient;
    /** @var AdScheduledBatchRepository&MockObject */
    private AdScheduledBatchRepository $batchRepo;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    /** @var Connection&MockObject */
    private Connection $connection;

    private AdBatchSchedulerCommand $command;

    protected function setUp(): void
    {
        $this->ozonClient = $this->createMock(OzonAdClient::class);
        $this->batchRepo = $this->createMock(AdScheduledBatchRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->command = new AdBatchSchedulerCommand(
            $this->ozonClient,
            $this->batchRepo,
            $this->em,
            $this->connection,
            new NullLogger(),
        );
    }

    public function testEmptyQueueCommitsAndExitsSuccess(): void
    {
        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        $this->batchRepo->method('findNextPlanned')->willReturn(null);

        $this->ozonClient->expects(self::never())->method('postStatistics');
        $this->em->expects(self::never())->method('flush');
        $this->batchRepo->expects(self::never())->method('save');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
    }

    public function testHappyPathMarksInFlightWithUuidAndStartedAt(): void
    {
        $batch = $this->buildBatch();

        $this->batchRepo->method('findNextPlanned')->willReturn($batch);
        $this->ozonClient->expects(self::once())
            ->method('postStatistics')
            ->willReturn('ozon-uuid-happy');

        $this->batchRepo->expects(self::once())->method('save')->with($batch);
        $this->em->expects(self::once())->method('flush');

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::IN_FLIGHT, $batch->getState());
        self::assertSame('ozon-uuid-happy', $batch->getOzonUuid());
        self::assertNotNull($batch->getStartedAt());
        self::assertNull($batch->getFinishedAt());
        self::assertNull($batch->getLastError());
        self::assertSame(0, $batch->getRetryCount());
    }

    public function testRateLimitKeepsPlannedAndSchedulesFuture(): void
    {
        $batch = $this->buildBatch();
        $originalScheduledAt = $batch->getScheduledAt();

        $this->batchRepo->method('findNextPlanned')->willReturn($batch);
        $this->ozonClient->method('postStatistics')
            ->willThrowException(new OzonRateLimitException('Ozon 429'));

        $this->batchRepo->expects(self::once())->method('save')->with($batch);
        $this->em->expects(self::once())->method('flush');

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::PLANNED, $batch->getState());
        self::assertNull($batch->getOzonUuid());
        self::assertSame(1, $batch->getRetryCount());
        self::assertNotNull($batch->getLastError());
        self::assertStringContainsString('429', (string) $batch->getLastError());
        self::assertGreaterThan($originalScheduledAt, $batch->getScheduledAt());
    }

    public function testPermanentFailureMarksFailedWithFinishedAt(): void
    {
        $batch = $this->buildBatch();

        $this->batchRepo->method('findNextPlanned')->willReturn($batch);
        $this->ozonClient->method('postStatistics')
            ->willThrowException(new OzonPermanentApiException('403 missing creds'));

        $this->batchRepo->expects(self::once())->method('save')->with($batch);
        $this->em->expects(self::once())->method('flush');

        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollBack');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));

        self::assertSame(AdScheduledBatchState::FAILED, $batch->getState());
        self::assertNotNull($batch->getFinishedAt());
        self::assertNotNull($batch->getLastError());
        self::assertStringContainsString('permanent', (string) $batch->getLastError());
    }

    public function testTransientFailureRollsBackAndExitsFailure(): void
    {
        $batch = $this->buildBatch();

        $this->batchRepo->method('findNextPlanned')->willReturn($batch);
        $this->ozonClient->method('postStatistics')
            ->willThrowException(new \RuntimeException('Ozon POST вернул HTTP 502'));

        // Ни save, ни flush не должны вызываться — всё откатывается.
        $this->batchRepo->expects(self::never())->method('save');
        $this->em->expects(self::never())->method('flush');

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::never())->method('commit');

        $tester = new CommandTester($this->command);
        self::assertSame(1, $tester->execute([]), 'Transient — FAILURE');

        // Объект batch в памяти не изменился на successful path'е,
        // но мог быть частично модифицирован — это неважно, rollback на уровне БД
        // вернёт запись к исходному состоянию.
    }

    private function buildBatch(): AdScheduledBatch
    {
        return AdScheduledBatchBuilder::aBatch()
            ->withId('bbbbbbbb-bbbb-bbbb-bbbb-000000000001')
            ->withJobId('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
            ->withCompanyId('11111111-1111-1111-1111-111111111111')
            ->withIndex(0)
            ->withCampaignIds(['camp-1', 'camp-2'])
            ->withDateRange(
                new \DateTimeImmutable('2026-03-01'),
                new \DateTimeImmutable('2026-03-10'),
            )
            ->withScheduledAt(new \DateTimeImmutable('-1 hour'))
            ->build();
    }
}
