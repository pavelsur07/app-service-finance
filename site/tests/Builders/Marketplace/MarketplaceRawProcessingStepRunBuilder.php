<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceRawProcessingStepRun;
use App\Marketplace\Enum\PipelineStep;

final class MarketplaceRawProcessingStepRunBuilder
{
    public const DEFAULT_COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    public const DEFAULT_RUN_ID     = '33333333-3333-3333-3333-333333333333';

    private string $companyId       = self::DEFAULT_COMPANY_ID;
    private string $processingRunId = self::DEFAULT_RUN_ID;
    private PipelineStep $step      = PipelineStep::SALES;

    private function __construct() {}

    public static function aStepRun(): self
    {
        return new self();
    }

    public function withCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;
        return $clone;
    }

    public function withProcessingRunId(string $processingRunId): self
    {
        $clone = clone $this;
        $clone->processingRunId = $processingRunId;
        return $clone;
    }

    public function forStep(PipelineStep $step): self
    {
        $clone = clone $this;
        $clone->step = $step;
        return $clone;
    }

    public function build(): MarketplaceRawProcessingStepRun
    {
        return new MarketplaceRawProcessingStepRun(
            $this->companyId,
            $this->processingRunId,
            $this->step,
        );
    }
}
