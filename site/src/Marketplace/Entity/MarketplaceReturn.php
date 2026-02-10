<?php

namespace App\Marketplace\Entity;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceReturnRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceReturnRepository::class)]
#[ORM\Table(name: 'marketplace_returns')]
#[ORM\Index(columns: ['company_id', 'return_date'], name: 'idx_company_return_date')]
#[ORM\Index(columns: ['sale_id'], name: 'idx_return_sale')]
class MarketplaceReturn
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MarketplaceSale::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MarketplaceSale $sale = null; // Может быть null если не смогли связать

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $externalReturnId = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $returnDate;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $refundAmount;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $returnReason = null; // "defect", "wrong_size", "customer_request"

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $returnLogisticsCost = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawData = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        Company $company,
        Product $product,
        MarketplaceType $marketplace
    ) {
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

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getExternalReturnId(): ?string
    {
        return $this->externalReturnId;
    }

    public function setExternalReturnId(?string $externalReturnId): self
    {
        $this->externalReturnId = $externalReturnId;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReturnDate(): \DateTimeImmutable
    {
        return $this->returnDate;
    }

    public function setReturnDate(\DateTimeImmutable $returnDate): self
    {
        $this->returnDate = $returnDate;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getRefundAmount(): string
    {
        return $this->refundAmount;
    }

    public function setRefundAmount(string $refundAmount): self
    {
        $this->refundAmount = $refundAmount;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReturnReason(): ?string
    {
        return $this->returnReason;
    }

    public function setReturnReason(?string $returnReason): self
    {
        $this->returnReason = $returnReason;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getReturnLogisticsCost(): ?string
    {
        return $this->returnLogisticsCost;
    }

    public function setReturnLogisticsCost(?string $returnLogisticsCost): self
    {
        $this->returnLogisticsCost = $returnLogisticsCost;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
