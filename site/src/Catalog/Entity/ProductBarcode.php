<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(
    name: 'product_barcodes',
    indexes: [
        new ORM\Index(name: 'idx_product_barcodes_product', columns: ['product_id']),
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_product_barcode_company', columns: ['company_id', 'barcode']),
    ],
)]
class ProductBarcode
{
    public const TYPE_EAN13      = 'EAN13';
    public const TYPE_UPC        = 'UPC';
    public const TYPE_CODE128    = 'CODE128';
    public const TYPE_DATAMATRIX = 'DATAMATRIX';
    public const TYPE_OTHER      = 'OTHER';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    /**
     * Компания передаётся только как строковый UUID.
     * Прямая связь с Company entity запрещена правилами разработки.
     */
    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'barcodes')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 100)]
    private string $barcode;

    #[ORM\Column(length: 20, options: ['default' => self::TYPE_EAN13])]
    private string $type;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPrimary;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $companyId,
        Product $product,
        string $barcode,
        string $type = self::TYPE_EAN13,
        bool $isPrimary = false,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::notEmpty($barcode, 'Barcode cannot be empty.');
        Assert::oneOf($type, [
            self::TYPE_EAN13,
            self::TYPE_UPC,
            self::TYPE_CODE128,
            self::TYPE_DATAMATRIX,
            self::TYPE_OTHER,
        ]);

        $this->id        = $id;
        $this->companyId = $companyId;
        $this->product   = $product;
        $this->barcode   = trim($barcode);
        $this->type      = $type;
        $this->isPrimary = $isPrimary;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
