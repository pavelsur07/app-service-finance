<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingMetaDTO
{
    public function __construct(
        public string $id,
        public ?string $title,
        public string $sku,
        public string $marketplace,
    ) {}
}
