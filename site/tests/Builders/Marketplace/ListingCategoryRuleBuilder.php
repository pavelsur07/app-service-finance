<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\ListingCategoryRule;
use App\Marketplace\Entity\MarketplaceListingCategory;

final class ListingCategoryRuleBuilder
{
    private ?MarketplaceListingCategory $category = null;
    private string $sourceKey = 'wb:69';

    public static function aRule(): self
    {
        return new self();
    }

    public function forCategory(MarketplaceListingCategory $category): self
    {
        $clone = clone $this;
        $clone->category = $category;

        return $clone;
    }

    public function withSourceKey(string $sourceKey): self
    {
        $clone = clone $this;
        $clone->sourceKey = $sourceKey;

        return $clone;
    }

    public function build(): ListingCategoryRule
    {
        if (null === $this->category) {
            throw new \LogicException('Category is required. Call forCategory().');
        }

        return new ListingCategoryRule($this->category, $this->sourceKey);
    }
}
