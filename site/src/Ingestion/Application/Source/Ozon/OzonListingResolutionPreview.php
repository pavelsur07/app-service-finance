<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\DTO\ListingResolution;

final readonly class OzonListingResolutionPreview
{
    public function __construct(
        public ?ListingResolution $resolution,
        public bool $wouldCreate,
    ) {
    }
}
