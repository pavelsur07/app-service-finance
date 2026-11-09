<?php

declare(strict_types=1);

namespace App\Marketplace\DTO;

/**
 * Затраты категории без привязки к листингу за период. Знак как у
 * {@see ListingCostCategoryAggregateDTO}: расход > 0, сторно уменьшает.
 */
final readonly class UnallocatedCostCategoryAggregateDTO
{
    public function __construct(
        public string $marketplace,
        public string $categoryCode,
        public string $categoryName,
        public string $netAmount,
        public string $costsAmount,
        public string $stornoAmount,
    ) {
    }
}
