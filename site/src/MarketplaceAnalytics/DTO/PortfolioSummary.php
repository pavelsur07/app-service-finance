<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\DTO;

use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;

final readonly class PortfolioSummary
{
    public function __construct(
        public AnalysisPeriod $period,
        public string $totalRevenue,
        public ?string $totalProfit,
        public ?float $marginPercent,
        public ?string $previousRevenue,
        public ?string $previousProfit,
        public ?string $revenueDeltaAbsolute,
        public ?float $revenueDeltaPercent,
        public ?string $profitDeltaAbsolute,
        public ?float $profitDeltaPercent,
    ) {}
}
