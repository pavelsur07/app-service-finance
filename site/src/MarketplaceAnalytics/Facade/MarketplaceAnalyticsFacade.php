<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Facade;

use App\MarketplaceAnalytics\Application\AddCostMappingAction;
use App\MarketplaceAnalytics\Application\DeleteCostMappingAction;
use App\MarketplaceAnalytics\Application\GetPortfolioSummaryAction;
use App\MarketplaceAnalytics\Application\GetUnitEconomicsAction;
use App\MarketplaceAnalytics\Application\RecalcSnapshotAction;
use App\MarketplaceAnalytics\Application\RemapCostMappingAction;
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
        private AddCostMappingAction $addCostMappingAction,
        private DeleteCostMappingAction $deleteCostMappingAction,
        private RemapCostMappingAction $remapCostMappingAction,
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

    public function addCostMapping(
        string $companyId,
        string $marketplace,
        UnitEconomyCostType $unitEconomyCostType,
        string $costCategoryId,
        string $costCategoryName,
    ): UnitEconomyCostMapping {
        return ($this->addCostMappingAction)($companyId, $marketplace, $unitEconomyCostType, $costCategoryId, $costCategoryName);
    }

    public function deleteCostMapping(string $companyId, string $mappingId): void
    {
        ($this->deleteCostMappingAction)($companyId, $mappingId);
    }

    public function remapCostMapping(
        string $companyId,
        string $mappingId,
        UnitEconomyCostType $newType,
    ): UnitEconomyCostMapping {
        return ($this->remapCostMappingAction)($companyId, $mappingId, $newType);
    }
}
