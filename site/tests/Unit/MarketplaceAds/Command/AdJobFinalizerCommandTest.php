<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Command;

use App\MarketplaceAds\Command\AdJobFinalizerCommand;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\MarketplaceAds\Repository\AdLoadJobRepositoryInterface;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit-тесты {@see AdJobFinalizerCommand}: ветки tryFinalize() без реальной БД.
 *
 * Покрывают:
 *  - empty queue → SUCCESS + "No RUNNING jobs";
 *  - job без batch'ей → warning, не финализируется;
 *  - PLANNED есть → рано, не финализируется;
 *  - IN_FLIGHT есть → рано, не финализируется;
 *  - все OK → markCompleted;
 *  - все FAILED → markFailed "All N batches failed";
 *  - все ABANDONED → markFailed (ABANDONED считается как failed-total);
 *  - микс OK + FAILED + ABANDONED → markPartialSuccess "N of M batches failed";
 *  - transient per-job → continue на следующий.
 */
final class AdJobFinalizerCommandTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /** @var AdLoadJobRepositoryInterface&MockObject */
    private AdLoadJobRepositoryInterface $jobRepo;
    /** @var AdScheduledBatchRepository&MockObject */
    private AdScheduledBatchRepository $batchRepo;

    private AdJobFinalizerCommand $command;

    protected function setUp(): void
    {
        $this->jobRepo = $this->createMock(AdLoadJobRepositoryInterface::class);
        $this->batchRepo = $this->createMock(AdScheduledBatchRepository::class);

        $this->command = new AdJobFinalizerCommand(
            $this->jobRepo,
            $this->batchRepo,
            new NullLogger(),
        );
    }

    public function testEmptyQueueExitsSuccess(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([]);
        $this->batchRepo->expects(self::never())->method('countStatesForJob');
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No RUNNING jobs', $tester->getDisplay());
    }

    public function testJobWithoutBatchesIsSkipped(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn([]);

        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('still_running=1', $tester->getDisplay());
    }

    public function testJobWithPlannedBatchIsNotFinalized(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn([
            'PLANNED' => 2,
            'OK' => 1,
        ]);

        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('still_running=1', $tester->getDisplay());
    }

    public function testJobWithInFlightBatchIsNotFinalized(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn([
            'IN_FLIGHT' => 1,
            'OK' => 5,
        ]);

        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
    }

    public function testAllOkMarksCompleted(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn(['OK' => 26]);

        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markFailed');
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('finalized=1', $tester->getDisplay());
    }

    public function testAllFailedMarksFailed(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn(['FAILED' => 3]);

        $this->jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(self::JOB_ID, self::COMPANY_ID, 'All 3 batches failed')
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
    }

    public function testAbandonedCountsTowardFailureTotal(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn([
            'FAILED' => 2,
            'ABANDONED' => 1,
        ]);

        $this->jobRepo->expects(self::once())
            ->method('markFailed')
            ->with(self::JOB_ID, self::COMPANY_ID, 'All 3 batches failed')
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markPartialSuccess');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
    }

    public function testMixOkAndFailedMarksPartialSuccess(): void
    {
        $this->jobRepo->method('findAllRunning')->willReturn([$this->buildRunningJob()]);
        $this->batchRepo->method('countStatesForJob')->willReturn([
            'OK' => 5,
            'FAILED' => 2,
            'ABANDONED' => 1,
        ]);

        $this->jobRepo->expects(self::once())
            ->method('markPartialSuccess')
            ->with(self::JOB_ID, self::COMPANY_ID, '3 of 8 batches failed')
            ->willReturn(1);
        $this->jobRepo->expects(self::never())->method('markCompleted');
        $this->jobRepo->expects(self::never())->method('markFailed');

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
    }

    public function testPerJobIsolationOnTransientError(): void
    {
        $jobA = $this->buildRunningJob('aaaaaaaa-aaaa-aaaa-aaaa-00000000000a');
        $jobB = $this->buildRunningJob('aaaaaaaa-aaaa-aaaa-aaaa-00000000000b');

        $this->jobRepo->method('findAllRunning')->willReturn([$jobA, $jobB]);

        // Первый count бросает, второй — OK.
        $this->batchRepo->method('countStatesForJob')
            ->willReturnCallback(static function (string $jobId) use ($jobA): array {
                if ($jobId === $jobA->getId()) {
                    throw new \RuntimeException('DB hiccup');
                }

                return ['OK' => 1];
            });

        // markCompleted должен быть вызван только для jobB.
        $this->jobRepo->expects(self::once())
            ->method('markCompleted')
            ->with($jobB->getId())
            ->willReturn(1);

        $tester = new CommandTester($this->command);
        self::assertSame(0, $tester->execute([]));
        // Один finalized (jobB), один still_running (jobA, залоггирован как error).
        self::assertStringContainsString('finalized=1', $tester->getDisplay());
    }

    private function buildRunningJob(string $id = self::JOB_ID): AdLoadJob
    {
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->asRunning()
            ->build();

        // Всегда переопределяем id: не полагаемся на формулу builder'а
        // (`aaaaaaaa-...-%012d`), а фиксируем явное значение для expectations.
        $reflection = new \ReflectionProperty(AdLoadJob::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($job, $id);

        return $job;
    }
}
