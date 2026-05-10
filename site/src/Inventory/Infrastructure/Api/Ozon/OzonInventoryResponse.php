<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Api\Ozon;

final readonly class OzonInventoryResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public array $raw,
        public ?string $nextLastId,
    ) {
    }
}
