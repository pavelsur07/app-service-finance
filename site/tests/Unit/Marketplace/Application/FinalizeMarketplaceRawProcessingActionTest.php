<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application;

use App\Marketplace\Application\Command\FinalizeMarketplaceRawProcessingCommand;
use App\Marketplace\Application\FinalizeMarketplaceRawProcessingAction;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingRunBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingStepRunBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FinalizeMarketplaceRawProcessingActionTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testAllStepsCompletedFinalizesRunAsCompleted(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();

        $step1 = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step1->markRunning();
        $step1->markCompleted(10, 0, 0);

        $step2 = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step2->markRunning();
        $step2->markCompleted(5, 1, 2);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $action = $this->buildAction($run, [$step1, $step2], $em);
        $action(new FinalizeMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));

        self::assertSame(PipelineStatus::COMPLETED, $run->getStatus());
        self::assertNotNull($run->getFinishedAt());
        self::assertNull($run->getLastErrorMessage());

        $summary = $run->getSummary();
        self::assertSame(15, $summary['total_processed']);
        self::assertSame(1, $summary['total_failed']);
        self::assertSame(2, $summary['total_skipped']);
        self::assertSame(2, $summary['steps_total']);
        self::assertSame(2, $summary['steps_completed']);
        self::assertSame(0, $summary['steps_failed']);
    }

    public function testOneFailedStepFinalizesRunAsFailed(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();

        $step1 = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step1->markRunning();
        $step1->markCompleted(10, 0, 0);

        $step2 = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $step2->markRunning();
        $step2->markFailed('processor error', 3, 1, 0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $action = $this->buildAction($run, [$step1, $step2], $em);
        $action(new FinalizeMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));

        self::assertSame(PipelineStatus::FAILED, $run->getStatus());
        self::assertNotNull($run->getLastErrorMessage());
        self::assertStringContainsString('processor error', $run->getLastErrorMessage());

        $summary = $run->getSummary();
        self::assertSame(1, $summary['steps_failed']);
        self::assertSame(1, $summary['steps_completed']);
    }

    public function testNonTerminalStepDefersFinalization(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();

        $stepCompleted = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $stepCompleted->markRunning();
        $stepCompleted->markCompleted(5, 0, 0);

        $stepStillRunning = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->withCompanyId(self::COMPANY_ID)
            ->withProcessingRunId($run->getId())
            ->build();
        $stepStillRunning->markRunning(); // not terminal

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $action = $this->buildAction($run, [$stepCompleted, $stepStillRunning], $em);
        $action(new FinalizeMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));

        // run should remain RUNNING
        self::assertSame(PipelineStatus::RUNNING, $run->getStatus());
    }

    public function testAlreadyTerminalRunIsIdempotent(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->withCompanyId(self::COMPANY_ID)->build();
        $run->markCompleted();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $action = $this->buildAction($run, [], $em);
        $action(new FinalizeMarketplaceRawProcessingCommand(self::COMPANY_ID, $run->getId()));

        self::assertSame(PipelineStatus::COMPLETED, $run->getStatus());
    }

    public function testRunNotFoundThrows(): void
    {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn(null);

        $action = new FinalizeMarketplaceRawProcessingAction(
            $runRepo,
            $this->createMock(MarketplaceRawProcessingStepRunRepository::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );

        $this->expectException(\DomainException::class);

        $action(new FinalizeMarketplaceRawProcessingCommand(self::COMPANY_ID, 'no-such-run'));
    }

    // -------------------------------------------------------------------------

    private function buildAction(
        \App\Marketplace\Entity\MarketplaceRawProcessingRun $run,
        array $stepRuns,
        ?EntityManagerInterface $em = null,
    ): FinalizeMarketplaceRawProcessingAction {
        $runRepo = $this->createMock(MarketplaceRawProcessingRunRepository::class);
        $runRepo->method('findByIdAndCompany')->willReturn($run);

        $stepRepo = $this->createMock(MarketplaceRawProcessingStepRunRepository::class);
        $stepRepo->method('findByRunId')->willReturn($stepRuns);

        return new FinalizeMarketplaceRawProcessingAction(
            $runRepo,
            $stepRepo,
            $em ?? $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );
    }
}
