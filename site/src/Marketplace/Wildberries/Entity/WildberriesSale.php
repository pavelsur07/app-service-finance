<?php

namespace App\Marketplace\Wildberries\Entity;

use App\Entity\Company;
use App\Repository\Wildberries\WildberriesSaleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WildberriesSaleRepository::class)]
#[ORM\Table(name: 'wildberries_sales')]
#[ORM\UniqueConstraint(name: 'uniq_wb_sale_company_srid', columns: ['company_id', 'srid'])]
class WildberriesSale
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'wildberriesSales')]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 64)]
    private string $srid;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $soldAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplierArticle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $techSize = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(type: 'integer')]
    private int $quantity = 0;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $price = '0.00';

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $finishedPrice = '0.00';

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $forPay = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    private ?string $deliveryAmount = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $orderType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $saleStatus = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $warehouseName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $oblast = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $odid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $saleId = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $statusUpdatedAt = null;

    #[ORM\Column(type: 'json')]
    private array $raw = [];

    public function __construct(string $id, Company $company, string $srid)
    {
        $this->id = $id;
        $this->company = $company;
        $this->srid = $srid;
        $this->soldAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getSrid(): string
    {
        return $this->srid;
    }

    public function setSrid(string $srid): void
    {
        $this->srid = $srid;
    }

    public function getSoldAt(): \DateTimeImmutable
    {
        return $this->soldAt;
    }

    public function setSoldAt(\DateTimeImmutable $soldAt): void
    {
        $this->soldAt = $soldAt;
    }

    public function getSupplierArticle(): ?string
    {
        return $this->supplierArticle;
    }

    public function setSupplierArticle(?string $supplierArticle): void
    {
        $this->supplierArticle = $supplierArticle;
    }

    public function getTechSize(): ?string
    {
        return $this->techSize;
    }

    public function setTechSize(?string $techSize): void
    {
        $this->techSize = $techSize;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): void
    {
        $this->barcode = $barcode;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }

    public function getFinishedPrice(): string
    {
        return $this->finishedPrice;
    }

    public function setFinishedPrice(string $finishedPrice): void
    {
        $this->finishedPrice = $finishedPrice;
    }

    public function getForPay(): ?string
    {
        return $this->forPay;
    }

    public function setForPay(?string $forPay): void
    {
        $this->forPay = $forPay;
    }

    public function getDeliveryAmount(): ?string
    {
        return $this->deliveryAmount;
    }

    public function setDeliveryAmount(?string $deliveryAmount): void
    {
        $this->deliveryAmount = $deliveryAmount;
    }

    public function getOrderType(): ?string
    {
        return $this->orderType;
    }

    public function setOrderType(?string $orderType): void
    {
        $this->orderType = $orderType;
    }

    public function getSaleStatus(): ?string
    {
        return $this->saleStatus;
    }

    public function setSaleStatus(?string $saleStatus): void
    {
        $this->saleStatus = $saleStatus;
    }

    public function getWarehouseName(): ?string
    {
        return $this->warehouseName;
    }

    public function setWarehouseName(?string $warehouseName): void
    {
        $this->warehouseName = $warehouseName;
    }

    public function getOblast(): ?string
    {
        return $this->oblast;
    }

    public function setOblast(?string $oblast): void
    {
        $this->oblast = $oblast;
    }

    public function getOdid(): ?string
    {
        return $this->odid;
    }

    public function setOdid(?string $odid): void
    {
        $this->odid = $odid;
    }

    public function getSaleId(): ?string
    {
        return $this->saleId;
    }

    public function setSaleId(?string $saleId): void
    {
        $this->saleId = $saleId;
    }

    public function getStatusUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->statusUpdatedAt;
    }

    public function setStatusUpdatedAt(?\DateTimeImmutable $statusUpdatedAt): void
    {
        $this->statusUpdatedAt = $statusUpdatedAt;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function setRaw(array $raw): void
    {
        $this->raw = $raw;
    }
}
