<?php

declare(strict_types=1);

namespace App\Catalog\Entity;

use App\Company\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(
    name: 'product_purchase_prices',
    indexes: [
        new ORM\Index(name: 'idx_purchase_price_company_product_from', columns: ['company_id', 'product_id', 'effective_from']),
        new ORM\Index(name: 'idx_purchase_price_company_product_to', columns: ['company_id', 'product_id', 'effective_to']),
    ],
)]
class ProductPurchasePrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    // Дата начала действия закупочной цены.
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $effectiveFrom;

    // Дата окончания действия цены; NULL означает, что цена актуальна сейчас.
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveTo = null;

    // Цена в минимальных единицах валюты (например, копейки для RUB).
    #[ORM\Column(type: 'bigint')]
    private int $priceAmount;

    #[ORM\Column(length: 3, options: ['default' => 'RUB'])]
    private string $priceCurrency = 'RUB';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        Company $company,
        Product $product,
        \DateTimeImmutable $effectiveFrom,
        int $priceAmount,
        string $priceCurrency = 'RUB',
        ?string $note = null,
    ) {
        Assert::uuid($id);
        Assert::greaterThanEq($priceAmount, 0);
        Assert::length($priceCurrency, 3);

        $this->id = $id;
        $this->company = $company;
        $this->product = $product;
        $this->effectiveFrom = $effectiveFrom;
        $this->priceAmount = $priceAmount;
        $this->priceCurrency = strtoupper($priceCurrency);
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function getEffectiveTo(): ?\DateTimeImmutable
    {
        return $this->effectiveTo;
    }

    public function getPriceAmount(): int
    {
        return $this->priceAmount;
    }

    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function closeAt(?\DateTimeImmutable $effectiveTo): self
    {
        $this->effectiveTo = $effectiveTo;

        return $this;
    }
}
