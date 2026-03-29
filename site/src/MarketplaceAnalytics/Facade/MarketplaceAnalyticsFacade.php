<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Facade;

use App\MarketplaceAnalytics\Application\GetPortfolioSummaryAction;
use App\MarketplaceAnalytics\Application\GetUnitEconomicsAction;
use App\MarketplaceAnalytics\Application\RecalcSnapshotAction;
use App\MarketplaceAnalytics\Application\RemapCostMappingAction;
use App\MarketplaceAnalytics\Application\ResetCostMappingAction;
use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\DTO\ListingUnitEconomics;
use App\MarketplaceAnalytics\DTO\PortfolioSummary;
use App\MarketplaceAnalytics\Entity\UnitEconomyCostMapping;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;

final readonly class MarketplaceAnalyticsFacade
{
    public function __construct(
        private GetUnitEconomicsAction $getUnitEconomicsAction,
        private GetPortfolioSummaryAction $getPortfolioSummaryAction,
        private RecalcSnapshotAction $recalcSnapshotAction,
        private RemapCostMappingAction $remapCostMappingAction,
        private ResetCostMappingAction $resetCostMappingAction,
    ) {}

    /**
     * @return ListingUnitEconomics[]
     */
    public function getUnitEconomics(
        string $companyId,
        AnalysisPeriod $period,
        ?string $marketplace,
    ): array {
        return ($this->getUnitEconomicsAction)($companyId, $period, $marketplace);
    }

    public function getPortfolioSummary(
        string $companyId,
        AnalysisPeriod $period,
        ?string $marketplace,
    ): PortfolioSummary {
        return ($this->getPortfolioSummaryAction)($companyId, $period, $marketplace);
    }

    public function requestRecalc(string $companyId, AnalysisPeriod $period): string
    {
        return ($this->recalcSnapshotAction)($companyId, $period);
    }

    public function remapCostMapping(
        string $companyId,
        string $mappingId,
        UnitEconomyCostType $newType,
    ): UnitEconomyCostMapping {
        return ($this->remapCostMappingAction)($companyId, $mappingId, $newType);
    }

    public function resetCostMapping(
        string $companyId,
        string $mappingId,
    ): UnitEconomyCostMapping {
        return ($this->resetCostMappingAction)($companyId, $mappingId);
    }
}
