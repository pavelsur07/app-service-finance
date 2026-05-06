<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use Ramsey\Uuid\Uuid;

final class MarketplaceListingBuilder
{
    private string $id;
    private ?Company $company = null;
    private ?Product $product = null;
    private MarketplaceType $marketplace = MarketplaceType::WILDBERRIES;
    private string $marketplaceSku = 'sku-test-1';
    private string $price = '1000.00';

    private function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
    }

    public static function aListing(): self
    {
        return new self();
    }

    public function forCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withMarketplace(MarketplaceType $marketplace): self
    {
        $clone = clone $this;
        $clone->marketplace = $marketplace;

        return $clone;
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

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
        $clone->id = sprintf('33333333-3333-4333-8333-%012d', $index);

        return $clone;
    }

    public function withPrice(string $price): self
    {
        $clone = clone $this;
        $clone->price = $price;

        return $clone;
    }

    public function build(): MarketplaceListing
    {
        if ($this->company === null) {
            throw new \LogicException('Company is required. Call forCompany().');
        }

        $listing = new MarketplaceListing(
            $this->id,
            $this->company,
            $this->product,
            $this->marketplace,
        );
        $listing->setMarketplaceSku($this->marketplaceSku);
        $listing->setPrice($this->price);

        return $listing;
    }
}
