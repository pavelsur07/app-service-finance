<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Entity;

use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineStep;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingStepRunBuilder;
use PHPUnit\Framework\TestCase;

final class MarketplaceRawProcessingStepRunTest extends TestCase
{
    public function testInitialStatusIsPending(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();

        self::assertSame(PipelineStatus::PENDING, $step->getStatus());
        self::assertNull($step->getStartedAt());
        self::assertNull($step->getFinishedAt());
        self::assertSame(0, $step->getProcessedCount());
    }

    public function testMarkRunning(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();

        self::assertSame(PipelineStatus::RUNNING, $step->getStatus());
        self::assertNotNull($step->getStartedAt());
    }

    public function testMarkRunningThrowsWhenTerminal(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();
        $step->markCompleted(5, 0, 0);

        $this->expectException(\DomainException::class);
        $step->markRunning();
    }

    public function testMarkCompleted(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();
        $step->markCompleted(10, 2, 1, ['sale_id' => 'x'], ['details']);

        self::assertSame(PipelineStatus::COMPLETED, $step->getStatus());
        self::assertNotNull($step->getFinishedAt());
        self::assertSame(10, $step->getProcessedCount());
        self::assertSame(2, $step->getFailedCount());
        self::assertSame(1, $step->getSkippedCount());
        self::assertNull($step->getErrorMessage());
    }

    public function testMarkFailed(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();
        $step->markFailed('processor error', 3, 1, 0);

        self::assertSame(PipelineStatus::FAILED, $step->getStatus());
        self::assertSame('processor error', $step->getErrorMessage());
        self::assertSame(3, $step->getProcessedCount());
        self::assertSame(1, $step->getFailedCount());
    }

    public function testMarkFailedThrowsWhenTerminal(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();
        $step->markCompleted(5, 0, 0);

        $this->expectException(\DomainException::class);
        $step->markFailed('late error');
    }

    public function testResetForRetryResetsToPending(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();
        $step->markFailed('step error', 3, 1, 0);

        $step->resetForRetry();

        self::assertSame(PipelineStatus::PENDING, $step->getStatus());
        self::assertNull($step->getStartedAt());
        self::assertNull($step->getFinishedAt());
        self::assertNull($step->getErrorMessage());
        self::assertSame(0, $step->getProcessedCount());
        self::assertSame(0, $step->getFailedCount());
        self::assertSame(0, $step->getSkippedCount());
        self::assertNull($step->getCreatedEntitiesJson());
        self::assertNull($step->getDetailsJson());
    }

    public function testResetForRetryThrowsWhenNotFailed(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        // PENDING, not FAILED

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only FAILED step runs can be retried.');

        $step->resetForRetry();
    }

    public function testResetForRetryThrowsWhenCompleted(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()->build();
        $step->markRunning();
        $step->markCompleted(5, 0, 0);

        $this->expectException(\DomainException::class);
        $step->resetForRetry();
    }

    public function testStepAssignment(): void
    {
        $step = MarketplaceRawProcessingStepRunBuilder::aStepRun()
            ->forStep(PipelineStep::RETURNS)
            ->build();

        self::assertSame(PipelineStep::RETURNS, $step->getStep());
    }
}
