<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds;

use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage;
use App\MarketplaceAds\Message\LoadOzonAdStatisticsRangeMessage;
use App\MarketplaceAds\MessageHandler\LoadOzonAdStatisticsRangeHandler;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit-тесты LoadOzonAdStatisticsRangeHandler.
 *
 * Ключевые инварианты:
 *  - разбиение диапазона: 62-дневное окно включительно (шаг cursor = +61 день);
 *  - markRunning + setChunksTotal выполняются только на первом проходе;
 *  - на retry (RUNNING + chunksTotal > 0) — идемпотентный dispatch без повторного set;
 *  - терминальный и отсутствующий job — no-op (OzonAdClient не дёргается,
 *    FetchOzonAdStatisticsMessage не отправляется).
 */
final class LoadOzonAdStatisticsRangeHandlerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testSingleDayRangeProducesOneChunk(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(
                new \DateTimeImmutable('2026-01-15'),
                new \DateTimeImmutable('2026-01-15'),
            )
            ->build();

        $dispatched = $this->runHandlerAndCaptureDispatch($job, expectedChunks: 1);

        self::assertCount(1, $dispatched);
        self::assertSame('2026-01-15', $dispatched[0]->dateFrom);
        self::assertSame('2026-01-15', $dispatched[0]->dateTo);
    }

    public function testExactly62DayRangeProducesOneChunk(): void
    {
        // 62 дня включительно: 2026-01-01..2026-03-03.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-03-03'),
            )
            ->build();

        $dispatched = $this->runHandlerAndCaptureDispatch($job, expectedChunks: 1);

        self::assertCount(1, $dispatched);
        self::assertSame('2026-01-01', $dispatched[0]->dateFrom);
        self::assertSame('2026-03-03', $dispatched[0]->dateTo);
        self::assertSame(62, $job->getTotalDays());
    }

    public function test63DayRangeProducesTwoChunks(): void
    {
        // 63 дня: 2026-01-01..2026-03-04 → [01-01..03-03] + [03-04..03-04].
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-03-04'),
            )
            ->build();

        $dispatched = $this->runHandlerAndCaptureDispatch($job, expectedChunks: 2);

        self::assertCount(2, $dispatched);
        self::assertSame('2026-01-01', $dispatched[0]->dateFrom);
        self::assertSame('2026-03-03', $dispatched[0]->dateTo);
        self::assertSame('2026-03-04', $dispatched[1]->dateFrom);
        self::assertSame('2026-03-04', $dispatched[1]->dateTo);
    }

    public function testFullYearRangeProducesSixChunks(): void
    {
        // 365 дней: 2026-01-01..2026-12-31. Ровно 5 × 62 = 310 + один хвостовой 55-дневный чанк.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-12-31'),
            )
            ->build();

        $dispatched = $this->runHandlerAndCaptureDispatch($job, expectedChunks: 6);

        self::assertCount(6, $dispatched);
        self::assertSame('2026-01-01', $dispatched[0]->dateFrom);
        self::assertSame('2026-12-31', $dispatched[5]->dateTo);

        $totalDays = 0;
        foreach ($dispatched as $msg) {
            $from = new \DateTimeImmutable($msg->dateFrom);
            $to = new \DateTimeImmutable($msg->dateTo);
            $days = (int) $from->diff($to)->days + 1;
            self::assertLessThanOrEqual(62, $days, 'chunk exceeds Ozon limit');
            $totalDays += $days;
        }
        self::assertSame(365, $totalDays, 'sum of chunk lengths must equal total days');
    }

    public function testPendingJobTransitionsToRunningAndSetsChunksTotal(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-03-04'), // 63 дня → 2 чанка
            )
            ->build();

        self::assertSame(AdLoadJobStatus::PENDING, $job->getStatus());
        self::assertSame(0, $job->getChunksTotal());

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('find')->willReturn($job);

        // Один объединённый flush: markRunning() + setChunksTotal() коммитим одним round-trip'ом.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler($jobRepo, $messageBus, $em);
        $handler(new LoadOzonAdStatisticsRangeMessage($job->getId()));

        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus());
        self::assertSame(2, $job->getChunksTotal(), 'chunksTotal должен совпасть с числом реальных чанков');
        self::assertNotNull($job->getStartedAt());
    }

    public function testRunningJobSkipsMarkRunningAndSkipsSetChunksTotalIfAlreadySet(): void
    {
        // Retry-сценарий: воркер упал после dispatch'а части чанков,
        // Messenger ретраит LoadOzonAdStatisticsRangeMessage. Job уже в RUNNING,
        // chunksTotal выставлен — повторный set / markRunning недопустим.
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withDateRange(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-03-04'),
            )
            ->withChunksTotal(2)
            ->asRunning()
            ->build();

        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus());
        self::assertSame(2, $job->getChunksTotal());
        $startedAtBefore = $job->getStartedAt();

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('find')->willReturn($job);

        // Ни одного flush: markRunning не вызван (job уже RUNNING),
        // setChunksTotal не вызван (chunksTotal уже 2).
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $handler = $this->createHandler($jobRepo, $messageBus, $em);
        $handler(new LoadOzonAdStatisticsRangeMessage($job->getId()));

        self::assertSame(AdLoadJobStatus::RUNNING, $job->getStatus());
        self::assertSame(2, $job->getChunksTotal(), 'chunksTotal не должен переписываться на retry');
        self::assertSame($startedAtBefore, $job->getStartedAt(), 'startedAt фиксируется только один раз');
    }

    public function testCompletedJobIsSkipped(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asCompleted()
            ->build();

        $this->assertTerminalJobIsNoOp($job);
    }

    public function testFailedJobIsSkipped(): void
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->asFailed('previous run')
            ->build();

        $this->assertTerminalJobIsNoOp($job);
    }

    public function testJobNotFoundLogsWarningAndReturns(): void
    {
        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($jobRepo, $messageBus, $em);

        // Не ретраим, не бросаем — просто возвращаемся.
        $handler(new LoadOzonAdStatisticsRangeMessage('00000000-0000-0000-0000-000000000000'));

        $this->addToAssertionCount(1);
    }

    private function assertTerminalJobIsNoOp(AdLoadJob $job): void
    {
        self::assertTrue($job->getStatus()->isTerminal());

        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('find')->willReturn($job);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $handler = $this->createHandler($jobRepo, $messageBus, $em);
        $handler(new LoadOzonAdStatisticsRangeMessage($job->getId()));
    }

    /**
     * @return list<FetchOzonAdStatisticsMessage>
     */
    private function runHandlerAndCaptureDispatch(AdLoadJob $job, int $expectedChunks): array
    {
        $jobRepo = $this->createMock(AdLoadJobRepository::class);
        $jobRepo->method('find')->willReturn($job);

        $em = $this->createMock(EntityManagerInterface::class);

        /** @var list<FetchOzonAdStatisticsMessage> $dispatched */
        $dispatched = [];
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly($expectedChunks))
            ->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                self::assertInstanceOf(FetchOzonAdStatisticsMessage::class, $msg);
                $dispatched[] = $msg;

                return new Envelope($msg);
            });

        $handler = $this->createHandler($jobRepo, $messageBus, $em);
        $handler(new LoadOzonAdStatisticsRangeMessage($job->getId()));

        return $dispatched;
    }

    private function createHandler(
        AdLoadJobRepository $jobRepo,
        MessageBusInterface $messageBus,
        EntityManagerInterface $em,
    ): LoadOzonAdStatisticsRangeHandler {
        return new LoadOzonAdStatisticsRangeHandler(
            $jobRepo,
            $messageBus,
            new AppLogger(new NullLogger(), $this->createMock(HubInterface::class)),
            $em,
        );
    }
}
