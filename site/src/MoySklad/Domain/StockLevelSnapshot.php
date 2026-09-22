<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

final readonly class StockLevelSnapshot
{
    public function __construct(
        public string $storeExternalId,
        public string $stock,
        public string $reserve,
        public string $inTransit,
    ) {
    }
}
