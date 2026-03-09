<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

final class PurchasePriceAtDto
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
        public readonly string $amount,  // decimal string, например '199.99'
        public readonly string $currency,
        public readonly ?string $note,
    ) {
    }
}
