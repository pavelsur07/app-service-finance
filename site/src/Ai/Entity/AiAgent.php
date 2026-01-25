<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use App\Ai\Enum\AiAgentType;
use App\Ai\Repository\AiAgentRepository;
use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: AiAgentRepository::class)]
#[ORM\Table(name: 'ai_agent')]
#[ORM\UniqueConstraint(name: 'uniq_ai_agent_company_type', columns: ['company_id', 'type'])]
class AiAgent
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(enumType: AiAgentType::class)]
    private AiAgentType $type;

    #[ORM\Column(type: 'boolean')]
    private bool $isEnabled;

    #[ORM\Column(type: 'json')]
    private array $settings;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Company $company, AiAgentType $type)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->company = $company;
        $this->type = $type;
        $this->isEnabled = true;
        $this->settings = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getType(): AiAgentType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function enable(): void
    {
        if (!$this->isEnabled) {
            $this->isEnabled = true;
            $this->touch();
        }
    }

    public function disable(): void
    {
        if ($this->isEnabled) {
            $this->isEnabled = false;
            $this->touch();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): void
    {
        $this->settings = $settings;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
