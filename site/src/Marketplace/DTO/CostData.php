<?php

namespace App\Marketplace\DTO;

use App\Marketplace\Enum\MarketplaceType;

readonly class CostData
{
    public function __construct(
        public MarketplaceType $marketplace,
        public string $categoryCode, // Код категории затрат
        public string $amount,
        public \DateTimeImmutable $costDate,
        public ?string $marketplaceSku = null, // Nullable для общих затрат
        public ?string $description = null,
        public ?string $externalId = null,
        public ?array $rawData = null,
        public ?string $nmId = null,
        public ?string $tsName = null,
        public ?string $barcode = null,
    ) {
    }
}
