<?php

declare(strict_types=1);

namespace App\Catalog\DTO;

final class PurchasePriceAtDto
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly ?string $effectiveTo,
        public readonly int $amount,
        public readonly string $currency,
        public readonly ?string $note,
    ) {
    }
}
