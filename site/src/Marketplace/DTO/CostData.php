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
        public ?string $categoryId = null,  // UUID категории затрат (для CostMappingResolver)
        public ?string $marketplaceSku = null, // Nullable для общих затрат
        public ?string $description = null,
        public ?string $externalId = null,
        public ?array $rawData = null,
        public ?string $nmId = null,
        public ?string $tsName = null,
        public ?string $barcode = null,
        /**
         * 'charge' | 'storno' | null.
         *
         * Source of truth для классификации "начисление vs сторно".
         * Populated MarketplaceFacade::getCostsForListingAndDate() напрямую из
         * c.operation_type (после Phase 2B колонка гарантированно NOT NULL).
         *
         * Адаптерам (Ozon/Wildberries) можно не передавать — оставится null.
         */
        public ?string $operationType = null,
    ) {
    }
}
