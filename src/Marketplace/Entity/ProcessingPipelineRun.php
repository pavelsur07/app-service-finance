<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Enum\PipelineTrigger;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'marketplace_pipeline_runs')]
#[ORM\UniqueConstraint(
    name: 'uq_pipeline_run_company_marketplace',
    columns: ['company_id', 'marketplace']
)]
#[ORM\Index(columns: ['company_id'], name: 'idx_pipeline_run_company')]
class ProcessingPipelineRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class, length: 50)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'string', enumType: PipelineStatus::class, length: 20)]
    private PipelineStatus $status;

    #[ORM\Column(type: 'string', enumType: PipelineStep::class, length: 20, nullable: true)]
    private ?PipelineStep $currentStep = null;

    #[ORM\Column(type: 'string', enumType: PipelineStep::class, length: 20, nullable: true)]
    private ?PipelineStep $failedStep = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $salesCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $returnsCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $costsCount = 0;

    #[ORM\Column(type: 'string', enumType: PipelineTrigger::class, length: 20)]
    private PipelineTrigger $triggeredBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceType $marketplace,
        PipelineTrigger $triggeredBy,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id          = $id;
        $this->companyId   = $companyId;
        $this->marketplace = $marketplace;
        $this->triggeredBy = $triggeredBy;
        $this->status      = PipelineStatus::PENDING;
        $this->startedAt   = new \DateTimeImmutable();
    }

    // === Mutation methods ===

    public function markRunning(PipelineStep $step): void
    {
        $this->status      = PipelineStatus::RUNNING;
        $this->currentStep = $step;
    }

    public function markStepCompleted(PipelineStep $step, int $count): void
    {
        match ($step) {
            PipelineStep::SALES   => $this->salesCount   = $count,
            PipelineStep::RETURNS => $this->returnsCount = $count,
            PipelineStep::COSTS   => $this->costsCount   = $count,
            default               => throw new \UnexpectedValueException(
                sprintf('Unhandled PipelineStep: %s', $step->value)
            ),
        };
    }

    public function markCompleted(): void
    {
        $this->status      = PipelineStatus::COMPLETED;
        $this->currentStep = null;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markFailed(PipelineStep $step, string $errorMessage): void
    {
        $this->status       = PipelineStatus::FAILED;
        $this->failedStep   = $step;
        $this->currentStep  = null;
        $this->errorMessage = $errorMessage;
        $this->completedAt  = new \DateTimeImmutable();
    }

    public function restart(PipelineTrigger $triggeredBy): void
    {
        $this->status       = PipelineStatus::PENDING;
        $this->triggeredBy  = $triggeredBy;
        $this->currentStep  = null;
        $this->failedStep   = null;
        $this->errorMessage = null;
        $this->salesCount   = 0;
        $this->returnsCount = 0;
        $this->costsCount   = 0;
        $this->startedAt    = new \DateTimeImmutable();
        $this->completedAt  = null;
    }

    // === Getters ===

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getStatus(): PipelineStatus
    {
        return $this->status;
    }

    public function getCurrentStep(): ?PipelineStep
    {
        return $this->currentStep;
    }

    public function getFailedStep(): ?PipelineStep
    {
        return $this->failedStep;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getSalesCount(): int
    {
        return $this->salesCount;
    }

    public function getReturnsCount(): int
    {
        return $this->returnsCount;
    }

    public function getCostsCount(): int
    {
        return $this->costsCount;
    }

    public function getTriggeredBy(): PipelineTrigger
    {
        return $this->triggeredBy;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
