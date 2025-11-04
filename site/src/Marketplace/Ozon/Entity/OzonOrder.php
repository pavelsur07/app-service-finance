<?php

namespace App\Marketplace\Ozon\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ozon_orders')]
#[ORM\UniqueConstraint(name: 'uniq_company_posting', columns: ['company_id', 'posting_number'])]
#[ORM\Index(name: 'idx_scheme', columns: ['scheme'])]
#[ORM\Index(name: 'idx_status', columns: ['status'])]
#[ORM\Index(name: 'idx_ozon_updated_at', columns: ['ozon_updated_at'])]
#[ORM\Index(name: 'idx_company_scheme_ozon_updated_at', columns: ['company_id', 'scheme', 'ozon_updated_at'])]
class OzonOrder
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(length: 255)]
    private string $postingNumber;

    #[ORM\Column(length: 3)]
    private string $scheme;

    #[ORM\Column(length: 255)]
    private string $status = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $statusUpdatedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ozonCreatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ozonUpdatedAt = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $warehouseId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deliveryMethodName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentStatus = null;

    #[ORM\Column(type: 'json')]
    private array $raw = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company)
    {
        $this->id = $id;
        $this->company = $company;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->statusUpdatedAt = $this->createdAt;
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

    public function getPostingNumber(): string
    {
        return $this->postingNumber;
    }

    public function setPostingNumber(string $postingNumber): void
    {
        $this->postingNumber = $postingNumber;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function setScheme(string $scheme): void
    {
        $this->scheme = $scheme;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStatusUpdatedAt(): \DateTimeImmutable
    {
        return $this->statusUpdatedAt;
    }

    public function setStatusUpdatedAt(\DateTimeImmutable $statusUpdatedAt): void
    {
        $this->statusUpdatedAt = $statusUpdatedAt;
    }

    public function getOzonCreatedAt(): ?\DateTimeImmutable
    {
        return $this->ozonCreatedAt;
    }

    public function setOzonCreatedAt(?\DateTimeImmutable $v): void
    {
        $this->ozonCreatedAt = $v;
    }

    public function getOzonUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->ozonUpdatedAt;
    }

    public function setOzonUpdatedAt(?\DateTimeImmutable $v): void
    {
        $this->ozonUpdatedAt = $v;
    }

    public function getWarehouseId(): ?string
    {
        return $this->warehouseId;
    }

    public function setWarehouseId(?string $warehouseId): void
    {
        $this->warehouseId = $warehouseId;
    }

    public function getDeliveryMethodName(): ?string
    {
        return $this->deliveryMethodName;
    }

    public function setDeliveryMethodName(?string $deliveryMethodName): void
    {
        $this->deliveryMethodName = $deliveryMethodName;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(?string $paymentStatus): void
    {
        $this->paymentStatus = $paymentStatus;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function setRaw(array $raw): void
    {
        $this->raw = $raw;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
