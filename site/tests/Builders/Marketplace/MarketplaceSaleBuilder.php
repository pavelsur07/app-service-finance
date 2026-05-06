<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use Ramsey\Uuid\Uuid;

final class MarketplaceSaleBuilder
{
    private string $id;
    private ?Company $company = null;
    private ?MarketplaceListing $listing = null;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $externalOrderId;
    private \DateTimeImmutable $saleDate;
    private int $quantity = 1;
    private string $pricePerUnit = '1000.00';
    private string $totalRevenue = '1000.00';
    private ?string $costPrice = null;

    private function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
        $this->externalOrderId = 'ext-' . Uuid::uuid4()->toString();
        $this->saleDate = new \DateTimeImmutable('2026-04-15');
    }

    public static function aSale(): self
    {
        return new self();
    }

    public function forCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function forListing(MarketplaceListing $listing): self
    {
        $clone = clone $this;
        $clone->listing = $listing;
        $clone->marketplace = $listing->getMarketplace();

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function withIndex(int $index): self
    {
        $clone = clone $this;
        $clone->id = sprintf('44444444-4444-4444-8444-%012d', $index);

        return $clone;
    }

    public function withExternalOrderId(string $externalOrderId): self
    {
        $clone = clone $this;
        $clone->externalOrderId = $externalOrderId;

        return $clone;
    }

    public function withSaleDate(\DateTimeImmutable $saleDate): self
    {
        $clone = clone $this;
        $clone->saleDate = $saleDate;

        return $clone;
    }

    public function withQuantity(int $quantity): self
    {
        $clone = clone $this;
        $clone->quantity = $quantity;

        return $clone;
    }

    public function withPricePerUnit(string $pricePerUnit): self
    {
        $clone = clone $this;
        $clone->pricePerUnit = $pricePerUnit;

        return $clone;
    }

    public function withTotalRevenue(string $totalRevenue): self
    {
        $clone = clone $this;
        $clone->totalRevenue = $totalRevenue;

        return $clone;
    }

    public function withCostPrice(?string $costPrice): self
    {
        $clone = clone $this;
        $clone->costPrice = $costPrice;

        return $clone;
    }

    public function build(): MarketplaceSale
    {
        if ($this->company === null) {
            throw new \LogicException('Company is required. Call forCompany().');
        }
        if ($this->listing === null) {
            throw new \LogicException('Listing is required. Call forListing().');
        }

        $sale = new MarketplaceSale(
            $this->id,
            $this->company,
            $this->listing,
            $this->marketplace,
        );
        $sale->setExternalOrderId($this->externalOrderId);
        $sale->setSaleDate($this->saleDate);
        $sale->setQuantity($this->quantity);
        $sale->setPricePerUnit($this->pricePerUnit);
        $sale->setTotalRevenue($this->totalRevenue);
        if ($this->costPrice !== null) {
            $sale->setCostPrice($this->costPrice);
        }

        return $sale;
    }
}
