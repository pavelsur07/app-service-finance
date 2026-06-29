<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class MarketplaceListingEnsurePreviewDTO
{
    public function __construct(
        public string $marketplaceSku,
        public ?MarketplaceListingReferenceDTO $reference,
        public bool $canCreate,
        public bool $ambiguous,
    ) {
    }
}
