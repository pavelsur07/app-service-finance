<?php

declare(strict_types=1);

namespace App\Tests\Builders\Marketplace;

use App\Marketplace\Entity\MarketplaceListingTag;
use Ramsey\Uuid\Uuid;

final class MarketplaceListingTagBuilder
{
    private string $id;
    private ?string $companyId = null;
    private string $name = 'Зима';

    private function __construct()
    {
        $this->id = Uuid::uuid4()->toString();
    }

    public static function aTag(): self
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

    public function build(): MarketplaceListingTag
    {
        if (null === $this->companyId) {
            throw new \LogicException('Company id is required. Call forCompanyId().');
        }

        return new MarketplaceListingTag($this->id, $this->companyId, $this->name);
    }
}
