<?php

namespace App\Marketplace\Entity;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceListingRepository::class)]
#[ORM\Table(name: 'marketplace_listings')]
#[ORM\Index(columns: ['company_id', 'marketplace'], name: 'idx_company_marketplace')]
#[ORM\Index(columns: ['marketplace', 'marketplace_sku'], name: 'idx_marketplace_sku')]
#[ORM\UniqueConstraint(
    name: 'uniq_company_marketplace_sku_size',
    columns: ['company_id', 'marketplace', 'marketplace_sku', 'size']
)]
class MarketplaceListing
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(length: 100)]
    private string $marketplaceSku; // nm_id от WB

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplierSku = null; // sa_name от WB (артикул производителя)

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $size = null; // ts_name от WB (размер)

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $price;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $discountPrice = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $marketplaceData = null; // URL, rating, reviews, etc

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company, Product $product, MarketplaceType $marketplace)
    {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->product = $product;
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

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getMarketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function setMarketplaceSku(string $marketplaceSku): self
    {
        $this->marketplaceSku = $marketplaceSku;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDiscountPrice(): ?string
    {
        return $this->discountPrice;
    }

    public function setDiscountPrice(?string $discountPrice): self
    {
        $this->discountPrice = $discountPrice;
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

    public function getMarketplaceData(): ?array
    {
        return $this->marketplaceData;
    }

    public function setMarketplaceData(?array $marketplaceData): self
    {
        $this->marketplaceData = $marketplaceData;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSupplierSku(): ?string
    {
        return $this->supplierSku;
    }

    public function setSupplierSku(?string $supplierSku): self
    {
        $this->supplierSku = $supplierSku;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): self
    {
        $this->size = $size;
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
