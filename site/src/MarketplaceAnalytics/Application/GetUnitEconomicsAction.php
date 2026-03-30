<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Application;

use App\MarketplaceAnalytics\Domain\Service\UnitEconomicsAggregationPolicy;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\DTO\ListingUnitEconomics;

final class GetUnitEconomicsAction
{
    public function __construct(
        private readonly UnitEconomicsAggregationPolicy $policy,
    ) {}

    /**
     * @return ListingUnitEconomics[]
     */
    public function __invoke(
        string $companyId,
        AnalysisPeriod $period,
        ?string $marketplace,
    ): array {
        return $this->policy->aggregateForPeriod($companyId, $period, $marketplace);
    }
}
