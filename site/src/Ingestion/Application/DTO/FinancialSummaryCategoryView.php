<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

final readonly class FinancialSummaryCategoryView
{
    public function __construct(
        public string $categoryId,
        public string $categoryName,
        public string $flow,
        public int $amountMinor,
    ) {
    }

    /**
     * @return array{category_id: string, category_name: string, flow: string, amount_minor: int}
     */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'flow' => $this->flow,
            'amount_minor' => $this->amountMinor,
        ];
    }
}
