<?php

namespace App\Entity;

use App\Repository\ReportApiKeyRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity(repositoryClass: ReportApiKeyRepository::class)]
#[ORM\Table(name: '`report_api_key`')]
#[ORM\Index(name: 'idx_report_api_key_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_report_api_key_key_prefix', columns: ['key_prefix'])]
#[ORM\Index(name: 'idx_report_api_key_is_active', columns: ['is_active'])]
class ReportApiKey
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 16)]
    private string $keyPrefix;

    #[ORM\Column(type: 'text')]
    private string $keyHash;

    #[ORM\Column(length: 255)]
    private string $scopes = 'reports:read';

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    public function __construct(Company $company, string $keyPrefix, string $keyHash)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->company = $company;
        $this->keyPrefix = $keyPrefix;
        $this->keyHash = $keyHash;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getKeyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getScopes(): string
    {
        return $this->scopes;
    }

    public function setScopes(string $scopes): self
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markAsUsed(?DateTimeImmutable $usedAt = null): void
    {
        $this->lastUsedAt = $usedAt ?? new DateTimeImmutable();
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }
}
