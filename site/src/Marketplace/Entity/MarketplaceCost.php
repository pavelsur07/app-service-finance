<?php

namespace App\Marketplace\Entity;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceCostRepository::class)]
#[ORM\Table(name: 'marketplace_costs')]
#[ORM\Index(columns: ['company_id', 'cost_date'], name: 'idx_company_cost_date')]
#[ORM\Index(columns: ['category_id'], name: 'idx_cost_category')]
#[ORM\Index(columns: ['product_id'], name: 'idx_cost_product')]
#[ORM\Index(columns: ['sale_id'], name: 'idx_cost_sale')]
class MarketplaceCost
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\ManyToOne(targetEntity: MarketplaceCostCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MarketplaceCostCategory $category;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null; // Nullable для общих затрат (например, реклама)

    #[ORM\ManyToOne(targetEntity: MarketplaceSale::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MarketplaceSale $sale = null; // Nullable, если затрата не привязана к конкретной продаже

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $costDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalId = null; // ID из отчёта маркетплейса (если есть)

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $rawDocumentId = null; // Ссылка на MarketplaceRawDocument

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData = null; // Только эта строка (опционально)

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        MarketplaceType $marketplace,
        MarketplaceCostCategory $category,
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->marketplace = $marketplace;
        $this->category = $category;
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

    public function getCategory(): MarketplaceCostCategory
    {
        return $this->category;
    }

    public function setCategory(MarketplaceCostCategory $category): self
    {
        $this->category = $category;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSale(): ?MarketplaceSale
    {
        return $this->sale;
    }

    public function setSale(?MarketplaceSale $sale): self
    {
        $this->sale = $sale;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCostDate(): \DateTimeImmutable
    {
        return $this->costDate;
    }

    public function setCostDate(\DateTimeImmutable $costDate): self
    {
        $this->costDate = $costDate;
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

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRawData(): ?array
    {
        return $this->rawData;
    }

    public function setRawData(?array $rawData): self
    {
        $this->rawData = $rawData;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRawDocumentId(): ?string
    {
        return $this->rawDocumentId;
    }

    public function setRawDocumentId(?string $rawDocumentId): self
    {
        $this->rawDocumentId = $rawDocumentId;
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
