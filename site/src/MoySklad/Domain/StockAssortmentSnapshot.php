<?php

declare(strict_types=1);

namespace App\MoySklad\Domain;

final readonly class StockAssortmentSnapshot
{
    /** @param list<StockLevelSnapshot> $levels */
    public function __construct(
        public string $assortmentType,
        public string $assortmentExternalId,
        public array $levels,
    ) {
    }
}
