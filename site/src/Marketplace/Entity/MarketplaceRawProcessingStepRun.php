<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Repository\MarketplaceRawProcessingStepRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceRawProcessingStepRunRepository::class)]
#[ORM\Table(name: 'marketplace_raw_processing_step_runs')]
#[ORM\Index(columns: ['company_id', 'processing_run_id'], name: 'idx_step_run_company_run')]
#[ORM\UniqueConstraint(name: 'uniq_step_run_per_run', columns: ['processing_run_id', 'step'])]
class MarketplaceRawProcessingStepRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $processingRunId;

    #[ORM\Column(type: 'string', enumType: PipelineStep::class)]
    private PipelineStep $step;

    #[ORM\Column(type: 'string', enumType: PipelineStatus::class)]
    private PipelineStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $processedCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $failedCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $skippedCount = 0;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $createdEntitiesJson = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $detailsJson = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $companyId,
        string $processingRunId,
        PipelineStep $step,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($processingRunId);

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->processingRunId = $processingRunId;
        $this->step = $step;
        $this->status = PipelineStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function markRunning(): void
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException('Cannot restart a terminal step run.');
        }

        $this->status = PipelineStatus::RUNNING;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function markCompleted(
        int $processedCount,
        int $failedCount,
        int $skippedCount,
        ?array $createdEntitiesJson = null,
        ?array $detailsJson = null,
    ): void {
        if ($this->status !== PipelineStatus::RUNNING) {
            throw new \DomainException('Step run must be in RUNNING state to be marked completed.');
        }

        Assert::greaterThanEq($processedCount, 0);
        Assert::greaterThanEq($failedCount, 0);
        Assert::greaterThanEq($skippedCount, 0);

        $this->status = PipelineStatus::COMPLETED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->processedCount = $processedCount;
        $this->failedCount = $failedCount;
        $this->skippedCount = $skippedCount;
        $this->createdEntitiesJson = $createdEntitiesJson;
        $this->detailsJson = $detailsJson;
        $this->errorMessage = null;
    }

    public function markFailed(
        string $errorMessage,
        int $processedCount = 0,
        int $failedCount = 0,
        int $skippedCount = 0,
        ?array $detailsJson = null,
    ): void {
        if ($this->status->isTerminal()) {
            throw new \DomainException('Cannot fail a terminal step run.');
        }

        Assert::notEmpty($errorMessage);
        Assert::greaterThanEq($processedCount, 0);
        Assert::greaterThanEq($failedCount, 0);
        Assert::greaterThanEq($skippedCount, 0);

        $this->status = PipelineStatus::FAILED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->errorMessage = $errorMessage;
        $this->processedCount = $processedCount;
        $this->failedCount = $failedCount;
        $this->skippedCount = $skippedCount;
        $this->detailsJson = $detailsJson;
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getProcessingRunId(): string { return $this->processingRunId; }
    public function getStep(): PipelineStep { return $this->step; }
    public function getStatus(): PipelineStatus { return $this->status; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getProcessedCount(): int { return $this->processedCount; }
    public function getFailedCount(): int { return $this->failedCount; }
    public function getSkippedCount(): int { return $this->skippedCount; }
    public function getCreatedEntitiesJson(): ?array { return $this->createdEntitiesJson; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function getDetailsJson(): ?array { return $this->detailsJson; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
