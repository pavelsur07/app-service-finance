<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application\DTO;

use App\Marketplace\Enum\MarketplaceType;

final readonly class SetInventoryCostPriceResult
{
    public function __construct(
        public string $id,
        public bool $wasOverwritten,
        public MarketplaceType $marketplace,
    ) {
    }
}
