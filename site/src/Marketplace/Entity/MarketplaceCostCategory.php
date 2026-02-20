<?php

namespace App\Marketplace\Entity;

use App\Company\Entity\Company;
use App\Entity\PLCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostCategoryRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceCostCategoryRepository::class)]
#[ORM\Table(name: 'marketplace_cost_categories')]
#[ORM\Index(columns: ['company_id'], name: 'idx_cost_category_company')]
#[ORM\UniqueConstraint(name: 'uniq_company_marketplace_code', columns: ['company_id', 'marketplace', 'code'])]
class MarketplaceCostCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(length: 100)]
    private string $name; // "Комиссия Wildberries", "Логистика до клиента"

    #[ORM\Column(length: 50)]
    private string $code; // "commission", "logistics", "advertising"

    #[ORM\ManyToOne(targetEntity: PLCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PLCategory $plCategory = null; // Связь с категорией ОПиУ

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isSystem = false; // Системная категория (нельзя удалить)

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null; // Soft delete

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, MarketplaceType $marketplace)
    {
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPlCategory(): ?PLCategory
    {
        return $this->plCategory;
    }

    public function setPlCategory(?PLCategory $plCategory): self
    {
        $this->plCategory = $plCategory;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function softDelete(): self
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->isActive = false;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
