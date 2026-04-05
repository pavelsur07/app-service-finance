<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Entity;

use App\Marketplace\Enum\PipelineStatus;
use App\Tests\Builders\Marketplace\MarketplaceRawProcessingRunBuilder;
use PHPUnit\Framework\TestCase;

final class MarketplaceRawProcessingRunTest extends TestCase
{
    public function testInitialStatusIsRunning(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->build();

        self::assertSame(PipelineStatus::RUNNING, $run->getStatus());
        self::assertNull($run->getFinishedAt());
        self::assertNull($run->getLastErrorMessage());
    }

    public function testMarkCompleted(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->build();
        $run->markCompleted(['total_processed' => 5], ['steps' => []]);

        self::assertSame(PipelineStatus::COMPLETED, $run->getStatus());
        self::assertNotNull($run->getFinishedAt());
        self::assertNull($run->getLastErrorMessage());
        self::assertSame(['total_processed' => 5], $run->getSummary());
    }

    public function testMarkFailed(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->build();
        $run->markFailed('Something went wrong', ['total_processed' => 0]);

        self::assertSame(PipelineStatus::FAILED, $run->getStatus());
        self::assertNotNull($run->getFinishedAt());
        self::assertSame('Something went wrong', $run->getLastErrorMessage());
    }

    public function testResetForRetryResetsToRunning(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->build();
        $run->markFailed('error', ['steps_failed' => 1], ['steps' => []]);

        $run->resetForRetry();

        self::assertSame(PipelineStatus::RUNNING, $run->getStatus());
        self::assertNull($run->getFinishedAt());
        self::assertNull($run->getLastErrorMessage());
        self::assertNull($run->getSummary());
        self::assertNull($run->getDetails());
    }

    public function testResetForRetryThrowsWhenNotFailed(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->build();
        // run is RUNNING, not FAILED

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only FAILED runs can be reset for retry.');

        $run->resetForRetry();
    }

    public function testResetForRetryThrowsWhenCompleted(): void
    {
        $run = MarketplaceRawProcessingRunBuilder::aRun()->build();
        $run->markCompleted();

        $this->expectException(\DomainException::class);

        $run->resetForRetry();
    }

    public function testIsTerminalForCompletedAndFailed(): void
    {
        $runCompleted = MarketplaceRawProcessingRunBuilder::aRun()->build();
        $runCompleted->markCompleted();

        $runFailed = MarketplaceRawProcessingRunBuilder::aRun()->build();
        $runFailed->markFailed('error');

        $runRunning = MarketplaceRawProcessingRunBuilder::aRun()->build();

        self::assertTrue($runCompleted->getStatus()->isTerminal());
        self::assertTrue($runFailed->getStatus()->isTerminal());
        self::assertFalse($runRunning->getStatus()->isTerminal());
    }
}
