<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use Webmozart\Assert\Assert;

final readonly class ListingResolution
{
    public function __construct(
        public ?string $listingId,
        public ?string $listingSku,
    ) {
        if (null !== $this->listingId) {
            Assert::uuid($this->listingId);
        }

        if (null !== $this->listingSku) {
            Assert::notEmpty($this->listingSku);
        }
    }
}
