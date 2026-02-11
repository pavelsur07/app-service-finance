<?php

namespace App\Marketplace\Entity;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Entity\Document;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceSaleRepository::class)]
#[ORM\Table(name: 'marketplace_sales')]
#[ORM\Index(columns: ['company_id', 'sale_date'], name: 'idx_company_sale_date')]
#[ORM\Index(columns: ['marketplace', 'external_order_id'], name: 'idx_marketplace_order')]
#[ORM\UniqueConstraint(name: 'uniq_marketplace_srid', columns: ['marketplace', 'external_order_id'])]
class MarketplaceSale
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private MarketplaceListing $listing;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Product $product; // Денормализация для скорости запросов

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $document = null; // Связь с ОПиУ документом

    #[ORM\Column(type: 'string', enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(length: 100)]
    private string $externalOrderId; // ID заказа на маркетплейсе

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $saleDate;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $pricePerUnit;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalRevenue; // quantity * pricePerUnit

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
        MarketplaceListing $listing,
        Product $product,
        MarketplaceType $marketplace
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->listing = $listing;
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

    public function getListing(): MarketplaceListing
    {
        return $this->listing;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): self
    {
        $this->document = $document;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getExternalOrderId(): string
    {
        return $this->externalOrderId;
    }

    public function setExternalOrderId(string $externalOrderId): self
    {
        $this->externalOrderId = $externalOrderId;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getSaleDate(): \DateTimeImmutable
    {
        return $this->saleDate;
    }

    public function setSaleDate(\DateTimeImmutable $saleDate): self
    {
        $this->saleDate = $saleDate;
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

    public function getPricePerUnit(): string
    {
        return $this->pricePerUnit;
    }

    public function setPricePerUnit(string $pricePerUnit): self
    {
        $this->pricePerUnit = $pricePerUnit;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getTotalRevenue(): string
    {
        return $this->totalRevenue;
    }

    public function setTotalRevenue(string $totalRevenue): self
    {
        $this->totalRevenue = $totalRevenue;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function calculateTotalRevenue(): self
    {
        $this->totalRevenue = bcmul(
            $this->pricePerUnit,
            (string) $this->quantity,
            2
        );
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
