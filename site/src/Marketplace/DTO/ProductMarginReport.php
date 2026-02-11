<?php

namespace App\Marketplace\DTO;

readonly class ProductMarginReport
{
    public function __construct(
        public string $productId,
        public string $productName,
        public int $totalUnits,
        public int $returnedUnits,
        public int $netUnits,
        public string $totalRevenue,
        public string $refunds,
        public string $netRevenue,
        public string $costs,
        public string $cogs,
        public string $grossProfit,
        public string $marginPercent
    ) {}
}
