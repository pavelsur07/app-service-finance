<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

final readonly class ListingTagAssignResult
{
    public function __construct(
        public string $tagId,
        public string $tagName,
        public int $assigned,
    ) {
    }
}
