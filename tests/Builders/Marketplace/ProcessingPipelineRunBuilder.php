<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\ProcessingPipelineRun;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Enum\PipelineTrigger;

final class ProcessingPipelineRunBuilder
{
    public const DEFAULT_ID         = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    private string $id              = self::DEFAULT_ID;
    private string $companyId       = self::DEFAULT_COMPANY_ID;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private PipelineTrigger $triggeredBy = PipelineTrigger::AUTO;
    private PipelineStatus $status       = PipelineStatus::PENDING;
    private ?PipelineStep $failedStep    = null;
    private ?PipelineStep $currentStep   = null;
    private string $errorMessage         = '(no message)';
    private int $salesCount              = 0;
    private int $returnsCount            = 0;
    private int $costsCount              = 0;

    private function __construct()
    {
    }

    public static function aRun(): self
    {
        return new self();
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withStatus(PipelineStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withTrigger(PipelineTrigger $triggeredBy): self
    {
        $clone = clone $this;
        $clone->triggeredBy = $triggeredBy;

        return $clone;
    }

    public function withFailedStep(PipelineStep $failedStep): self
    {
        $clone = clone $this;
        $clone->failedStep = $failedStep;

        return $clone;
    }

    public function withCurrentStep(PipelineStep $currentStep): self
    {
        $clone = clone $this;
        $clone->currentStep = $currentStep;

        return $clone;
    }

    public function withErrorMessage(string $errorMessage): self
    {
        $clone = clone $this;
        $clone->errorMessage = $errorMessage;

        return $clone;
    }

    public function withCounts(int $sales, int $returns, int $costs): self
    {
        $clone = clone $this;
        $clone->salesCount   = $sales;
        $clone->returnsCount = $returns;
        $clone->costsCount   = $costs;

        return $clone;
    }

    public function build(): ProcessingPipelineRun
    {
        $run = new ProcessingPipelineRun(
            id: $this->id,
            companyId: $this->companyId,
            marketplace: $this->marketplace,
            triggeredBy: $this->triggeredBy,
        );

        if ($this->status === PipelineStatus::FAILED && $this->failedStep === null) {
            throw new \LogicException('withFailedStep() обязателен при withStatus(FAILED)');
        }

        if ($this->status === PipelineStatus::RUNNING) {
            $run->markRunning($this->currentStep ?? PipelineStep::SALES);
        } elseif ($this->status === PipelineStatus::COMPLETED) {
            $run->markCompleted();
        } elseif ($this->status === PipelineStatus::FAILED) {
            $run->markFailed($this->failedStep, $this->errorMessage);
        }

        if ($this->salesCount > 0) {
            $run->markStepCompleted(PipelineStep::SALES, $this->salesCount);
        }
        if ($this->returnsCount > 0) {
            $run->markStepCompleted(PipelineStep::RETURNS, $this->returnsCount);
        }
        if ($this->costsCount > 0) {
            $run->markStepCompleted(PipelineStep::COSTS, $this->costsCount);
        }

        return $run;
    }
}
