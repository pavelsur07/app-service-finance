<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use App\Ai\Enum\AiRunStatus;
use App\Ai\Repository\AiRunRepository;
use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: AiRunRepository::class)]
#[ORM\Table(name: 'ai_run')]
#[ORM\Index(name: 'idx_ai_run_company_started', columns: ['company_id', 'started_at'])]
class AiRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: AiAgent::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AiAgent $agent;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(enumType: AiRunStatus::class)]
    private AiRunStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $inputSummary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $output = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(AiAgent $agent)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->agent = $agent;
        $this->company = $agent->getCompany();
        $this->startedAt = new \DateTimeImmutable();
        $this->status = AiRunStatus::PENDING;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getAgent(): AiAgent
    {
        return $this->agent;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): AiRunStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return AiRunStatus::PENDING === $this->status;
    }

    public function getInputSummary(): ?string
    {
        return $this->inputSummary;
    }

    public function attachInputSummary(?string $summary): void
    {
        $this->inputSummary = $summary;
    }

    public function getOutput(): ?string
    {
        return $this->output;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function markAsSucceeded(?string $output = null): void
    {
        $this->status = AiRunStatus::SUCCESS;
        $this->finishedAt = new \DateTimeImmutable();
        $this->output = $output;
        $this->errorMessage = null;
    }

    public function markAsFailed(string $error): void
    {
        $this->status = AiRunStatus::FAILED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->errorMessage = $error;
    }
}
