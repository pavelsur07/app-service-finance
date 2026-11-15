<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

final readonly class ListingMetaPageDTO
{
    /** @param list<ListingMetaDTO> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $totalItems,
    ) {
    }
}
