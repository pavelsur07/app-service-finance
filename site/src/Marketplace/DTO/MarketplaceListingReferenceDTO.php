<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

use Webmozart\Assert\Assert;

final readonly class MarketplaceListingReferenceDTO
{
    public function __construct(
        public string $listingId,
        public string $marketplaceSku,
        public ?string $supplierSku = null,
    ) {
        Assert::uuid($this->listingId);
        Assert::notEmpty($this->marketplaceSku);

        if (null !== $this->supplierSku) {
            Assert::notEmpty($this->supplierSku);
        }
    }
}
