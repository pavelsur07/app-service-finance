<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineTrigger;
use App\Marketplace\Repository\MarketplaceRawProcessingRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceRawProcessingRunRepository::class)]
#[ORM\Table(name: 'marketplace_raw_processing_runs')]
#[ORM\Index(columns: ['company_id', 'raw_document_id', 'started_at'], name: 'idx_raw_run_company_doc_started')]
#[ORM\Index(columns: ['company_id', 'status', 'started_at'], name: 'idx_raw_run_company_status_started')]
#[ORM\Index(columns: ['retry_of_run_id'], name: 'idx_raw_run_retry_of')]
class MarketplaceRawProcessingRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'guid')]
    private string $rawDocumentId;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'string', length: 50)]
    private string $documentType;

    #[ORM\Column(name: 'pipeline_trigger', type: 'string', enumType: PipelineTrigger::class)]
    private PipelineTrigger $trigger;

    #[ORM\Column(type: 'string', enumType: PipelineStatus::class)]
    private PipelineStatus $status;

    #[ORM\Column(type: 'string', length: 100)]
    private string $profileCode;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastErrorMessage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summary = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $retryOfRunId = null;

    public function __construct(
        string $companyId,
        string $rawDocumentId,
        MarketplaceType $marketplace,
        string $documentType,
        PipelineTrigger $trigger,
        string $profileCode,
        ?string $retryOfRunId = null,
    ) {
        Assert::uuid($companyId);
        Assert::uuid($rawDocumentId);
        Assert::notEmpty($documentType);
        Assert::notEmpty($profileCode);

        if ($retryOfRunId !== null) {
            Assert::uuid($retryOfRunId);
        }

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->rawDocumentId = $rawDocumentId;
        $this->marketplace = $marketplace;
        $this->documentType = $documentType;
        $this->trigger = $trigger;
        $this->status = PipelineStatus::RUNNING;
        $this->profileCode = $profileCode;
        $this->startedAt = new \DateTimeImmutable();
        $this->retryOfRunId = $retryOfRunId;
    }

    public function markCompleted(?array $summary = null, ?array $details = null): void
    {
        $this->status = PipelineStatus::COMPLETED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->lastErrorMessage = null;
        $this->summary = $summary;
        $this->details = $details;
    }

    public function markFailed(string $errorMessage, ?array $summary = null, ?array $details = null): void
    {
        Assert::notEmpty($errorMessage);

        $this->status = PipelineStatus::FAILED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->lastErrorMessage = $errorMessage;
        $this->summary = $summary;
        $this->details = $details;
    }

    public function resetForRetry(): void
    {
        if ($this->status !== PipelineStatus::FAILED) {
            throw new \DomainException('Only FAILED runs can be reset for retry.');
        }

        $this->status = PipelineStatus::RUNNING;
        $this->finishedAt = null;
        $this->lastErrorMessage = null;
        $this->summary = null;
        $this->details = null;
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getRawDocumentId(): string { return $this->rawDocumentId; }
    public function getMarketplace(): MarketplaceType { return $this->marketplace; }
    public function getDocumentType(): string { return $this->documentType; }
    public function getTrigger(): PipelineTrigger { return $this->trigger; }
    public function getStatus(): PipelineStatus { return $this->status; }
    public function getProfileCode(): string { return $this->profileCode; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getLastErrorMessage(): ?string { return $this->lastErrorMessage; }
    public function getSummary(): ?array { return $this->summary; }
    public function getDetails(): ?array { return $this->details; }
    public function getRetryOfRunId(): ?string { return $this->retryOfRunId; }
}
