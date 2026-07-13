<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Api\Wildberries;

final readonly class WbInventoryResponse
{
    /**
     * @param array<string, mixed> $raw
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $raw,
        public array $items,
        public bool $hasNextPage,
    ) {
    }
}
