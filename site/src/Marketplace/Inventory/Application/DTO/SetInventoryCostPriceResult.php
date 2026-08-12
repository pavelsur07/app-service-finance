<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application\DTO;

final readonly class SetInventoryCostPriceResult
{
    public function __construct(
        public string $id,
        public bool $wasOverwritten,
    ) {
    }
}
