<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingCategoryDTO
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
