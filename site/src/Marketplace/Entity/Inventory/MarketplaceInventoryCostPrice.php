<?php

declare(strict_types=1);

namespace App\Marketplace\Entity\Inventory;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Inventory\Infrastructure\Repository\MarketplaceInventoryCostPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: MarketplaceInventoryCostPriceRepository::class)]
#[ORM\Table(
    name: 'marketplace_inventory_cost_prices',
    indexes: [
        new ORM\Index(
            name: 'idx_inv_cost_company_listing_from',
            columns: ['company_id', 'listing_id', 'effective_from'],
        ),
        new ORM\Index(
            name: 'idx_inv_cost_company_listing_to',
            columns: ['company_id', 'listing_id', 'effective_to'],
        ),
    ],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_inv_cost_listing_from',
    columns: ['listing_id', 'effective_from'],
)]
class MarketplaceInventoryCostPrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    /**
     * Компания передаётся только как строковый UUID.
     * Прямая связь с Company запрещена — используем companyId.
     */
    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    /**
     * Себестоимость привязана к листингу, а не к продукту.
     * Один листинг — одна история цен.
     */
    #[ORM\ManyToOne(targetEntity: MarketplaceListing::class)]
    #[ORM\JoinColumn(name: 'listing_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private MarketplaceListing $listing;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $effectiveTo = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $priceAmount;

    #[ORM\Column(length: 3, options: ['default' => 'RUB'])]
    private string $priceCurrency = 'RUB';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $companyId,
        MarketplaceListing $listing,
        \DateTimeImmutable $effectiveFrom,
        string $priceAmount,
        string $priceCurrency = 'RUB',
        ?string $note = null,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::numeric($priceAmount);
        Assert::greaterThanEq((float) $priceAmount, 0.0);
        Assert::length($priceCurrency, 3);

        $this->id            = $id;
        $this->companyId     = $companyId;
        $this->listing       = $listing;
        $this->effectiveFrom = $effectiveFrom;
        $this->priceAmount   = $priceAmount;
        $this->priceCurrency = strtoupper($priceCurrency);
        $this->note          = $note;
        $this->createdAt     = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getListing(): MarketplaceListing
    {
        return $this->listing;
    }

    public function getEffectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function getEffectiveTo(): ?\DateTimeImmutable
    {
        return $this->effectiveTo;
    }

    public function getPriceAmount(): string
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

    /**
     * Закрыть период действия цены.
     * Вызывается из SetInventoryCostPriceAction при добавлении новой записи.
     */
    public function closeAt(?\DateTimeImmutable $effectiveTo): self
    {
        $this->effectiveTo = $effectiveTo;

        return $this;
    }
}
