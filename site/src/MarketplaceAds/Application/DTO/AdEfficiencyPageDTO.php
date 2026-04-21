<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application\DTO;

final readonly class AdEfficiencyPageDTO
{
    /**
     * @param list<AdEfficiencyItemDTO> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public string $totalRevenue,
        public string $totalAdSpend,
        public ?string $totalDrrPercent,
    ) {
    }
}
