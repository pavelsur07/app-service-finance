<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class FinancialSummaryMarketplaceCategoryView
{
    public function __construct(
        public string $source,
        public string $categoryGroup,
        public string $categoryName,
        public string $type,
        public string $direction,
        public int $amountMinor,
        public int $txCount,
        public int $sortOrder,
    ) {
    }

    /**
     * @return array{source: string, category_group: string, category_name: string, type: string, direction: string, amount_minor: int, tx_count: int, sort_order: int}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'category_group' => $this->categoryGroup,
            'category_name' => $this->categoryName,
            'type' => $this->type,
            'direction' => $this->direction,
            'amount_minor' => $this->amountMinor,
            'tx_count' => $this->txCount,
            'sort_order' => $this->sortOrder,
        ];
    }
}
