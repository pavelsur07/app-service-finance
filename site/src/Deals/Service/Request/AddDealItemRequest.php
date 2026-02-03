<?php

namespace App\Deals\Service\Request;

use App\Deals\Enum\DealItemKind;

final class AddDealItemRequest
{
    public function __construct(
        public readonly string $name,
        public readonly DealItemKind $kind,
        public readonly string $qty,
        public readonly string $price,
        public readonly string $amount,
        public readonly int $lineIndex,
        public readonly ?string $unit = null,
    ) {
    }
}
