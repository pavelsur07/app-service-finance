<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceListingCategory;
use Ramsey\Uuid\Uuid;

final class MarketplaceListingCategoryBuilder
{
    private string $id;
    private ?string $companyId = null;
    private string $name = 'Платья';

    private function __construct()
    {
        $this->id = Uuid::uuid7()->toString();
    }

    public static function aCategory(): self
    {
        return new self();
    }

    public function forCompanyId(string $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function build(): MarketplaceListingCategory
    {
        if (null === $this->companyId) {
            throw new \LogicException('Company id is required. Call forCompanyId().');
        }

        return new MarketplaceListingCategory($this->companyId, $this->name, $this->id);
    }
}
