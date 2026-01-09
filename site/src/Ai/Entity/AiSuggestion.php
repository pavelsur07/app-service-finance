<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use App\Ai\Enum\AiSuggestionSeverity;
use App\Ai\Repository\AiSuggestionRepository;
use App\Entity\Company;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: AiSuggestionRepository::class)]
#[ORM\Table(name: 'ai_suggestion')]
#[ORM\Index(name: 'idx_ai_suggestion_company_created', columns: ['company_id', 'created_at'])]
class AiSuggestion
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

    #[ORM\ManyToOne(targetEntity: AiRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AiRun $run;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(enumType: AiSuggestionSeverity::class)]
    private AiSuggestionSeverity $severity;

    #[ORM\Column(type: 'boolean')]
    private bool $isRead = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isApplied = false;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $relatedEntityType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $relatedEntityId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Company $company,
        AiAgent $agent,
        AiRun $run,
        string $title,
        string $description,
        AiSuggestionSeverity $severity
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->company = $company;
        $this->agent = $agent;
        $this->run = $run;
        $this->title = $title;
        $this->description = $description;
        $this->severity = $severity;
        $this->createdAt = new DateTimeImmutable();
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

    public function getRun(): AiRun
    {
        return $this->run;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSeverity(): AiSuggestionSeverity
    {
        return $this->severity;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function markAsRead(): void
    {
        $this->isRead = true;
    }

    public function isApplied(): bool
    {
        return $this->isApplied;
    }

    public function markAsApplied(): void
    {
        $this->isApplied = true;
    }

    public function getRelatedEntityType(): ?string
    {
        return $this->relatedEntityType;
    }

    public function getRelatedEntityId(): ?string
    {
        return $this->relatedEntityId;
    }

    public function relateTo(?string $entityType, ?string $entityId): void
    {
        $this->relatedEntityType = $entityType;
        $this->relatedEntityId = $entityId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
