<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Catalog\Enum\ProductStatus;
use App\Company\Entity\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(
    name: '`products`',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_company_sku', columns: ['company_id', 'sku']),
        new ORM\UniqueConstraint(name: 'uniq_company_internal_article', columns: ['company_id', 'internal_article']),
    ],
)]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\Column(length: 100)]
    private string $sku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Артикул продавца/поставщика (внешний).
     * Необязателен, не уникален глобально.
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $vendorSku = null;

    /**
     * Внутренний артикул системы формата PRD-{YYYY}-{NNNNNN}.
     * Генерируется автоматически. Уникален в рамках компании.
     * После присвоения не изменяется.
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $internalArticle = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, nullable: true)]
    private ?string $weightKg = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $dimensions = null;

    #[ORM\Column(type: 'string', enumType: ProductStatus::class)]
    private ProductStatus $status;

    /** @var Collection<int, ProductBarcode> */
    #[ORM\OneToMany(targetEntity: ProductBarcode::class, mappedBy: 'product', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $barcodes;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Company $company)
    {
        Assert::uuid($id);
        $this->id        = $id;
        $this->company   = $company;
        $this->status    = ProductStatus::ACTIVE;
        $this->barcodes  = new ArrayCollection();
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

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku       = $sku;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name      = $name;
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
        $this->updatedAt   = new \DateTimeImmutable();

        return $this;
    }

    public function getVendorSku(): ?string
    {
        return $this->vendorSku;
    }

    public function setVendorSku(?string $vendorSku): self
    {
        $this->vendorSku = $vendorSku !== null ? trim($vendorSku) : null;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getInternalArticle(): ?string
    {
        return $this->internalArticle;
    }

    /**
     * Присваивается один раз при создании товара. Повторное присвоение запрещено.
     */
    public function assignInternalArticle(string $internalArticle): self
    {
        if (null !== $this->internalArticle) {
            throw new \LogicException('Internal article is already assigned and cannot be changed.');
        }

        $this->internalArticle = $internalArticle;
        $this->updatedAt       = new \DateTimeImmutable();

        return $this;
    }

    public function getWeightKg(): ?string
    {
        return $this->weightKg;
    }

    public function setWeightKg(?string $weightKg): self
    {
        $this->weightKg  = $weightKg;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    public function setDimensions(?array $dimensions): self
    {
        $this->dimensions = $dimensions;
        $this->updatedAt  = new \DateTimeImmutable();

        return $this;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function setStatus(ProductStatus $status): self
    {
        $this->status    = $status;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** @return Collection<int, ProductBarcode> */
    public function getBarcodes(): Collection
    {
        return $this->barcodes;
    }

    public function addBarcode(ProductBarcode $barcode): self
    {
        if (!$this->barcodes->contains($barcode)) {
            $this->barcodes->add($barcode);
        }

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
