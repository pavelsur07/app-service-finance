<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Domain\Service\PortfolioSummaryPolicy;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\DTO\PortfolioSummary;

final class GetPortfolioSummaryAction
{
    public function __construct(
        private readonly PortfolioSummaryPolicy $policy,
    ) {}

    public function __invoke(
        string $companyId,
        AnalysisPeriod $period,
        ?string $marketplace,
    ): PortfolioSummary {
        return $this->policy->calculate($companyId, $period, $marketplace);
    }
}
