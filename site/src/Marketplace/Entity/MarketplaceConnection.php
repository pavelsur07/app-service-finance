<?php

namespace App\Marketplace\Entity;

use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceConnectionRepository::class)]
#[ORM\Table(name: 'marketplace_connections')]
#[ORM\Index(columns: ['company_id'], name: 'idx_connection_company')]
#[ORM\UniqueConstraint(name: 'uniq_company_marketplace', columns: ['company_id', 'marketplace'])]
class MarketplaceConnection
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'text')]
    private string $apiKey; // TODO: Encrypt in production

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessfulSyncAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastSyncError = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null; // Дополнительные настройки

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        MarketplaceType $marketplace
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->marketplace = $marketplace;
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

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeImmutable $lastSyncAt): self
    {
        $this->lastSyncAt = $lastSyncAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastSuccessfulSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessfulSyncAt;
    }

    public function setLastSuccessfulSyncAt(?\DateTimeImmutable $lastSuccessfulSyncAt): self
    {
        $this->lastSuccessfulSyncAt = $lastSuccessfulSyncAt;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    public function setLastSyncError(?string $lastSyncError): self
    {
        $this->lastSyncError = $lastSyncError;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markSyncStarted(): self
    {
        $this->lastSyncAt = new \DateTimeImmutable();
        $this->lastSyncError = null;

        return $this;
    }

    public function markSyncSuccess(): self
    {
        $this->lastSuccessfulSyncAt = new \DateTimeImmutable();
        $this->lastSyncError = null;

        return $this;
    }

    public function markSyncFailed(string $error): self
    {
        $this->lastSyncError = $error;

        return $this;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): self
    {
        $this->settings = $settings;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
