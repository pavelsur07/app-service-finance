<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Domain\ValueObject\CostBreakdown;
use App\MarketplaceAnalytics\DTO\PortfolioSummary;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;

final readonly class PortfolioSummaryPolicy
{
    public function __construct(
        private ListingDailySnapshotRepositoryInterface $snapshotRepository,
    ) {}

    public function calculate(
        string $companyId,
        AnalysisPeriod $period,
        ?string $marketplace,
    ): PortfolioSummary {
        $snapshots = $this->snapshotRepository->findByCompanyAndPeriod(
            $companyId,
            $period->dateFrom,
            $period->dateTo,
            $marketplace,
        );

        [$totalRevenue, $totalProfit] = $this->computeTotals($snapshots);

        $marginPercent = null;
        if (bccomp($totalRevenue, '0.00', 2) > 0 && $totalProfit !== null) {
            $marginPercent = (float) bcdiv(bcmul($totalProfit, '100', 2), $totalRevenue, 2);
        }

        $prevPeriod = $period->previousPeriod();
        $prevSnapshots = $this->snapshotRepository->findByCompanyAndPeriod(
            $companyId,
            $prevPeriod->dateFrom,
            $prevPeriod->dateTo,
            $marketplace,
        );

        [$previousRevenue, $previousProfit] = $this->computeTotals($prevSnapshots);

        $revenueDeltaAbsolute = null;
        $revenueDeltaPercent = null;
        if (bccomp($previousRevenue, '0.00', 2) > 0) {
            $revenueDeltaAbsolute = bcsub($totalRevenue, $previousRevenue, 2);
            $revenueDeltaPercent = (float) bcdiv(
                bcmul($revenueDeltaAbsolute, '100', 2),
                $previousRevenue,
                2,
            );
        } elseif (bccomp($totalRevenue, '0.00', 2) === 0) {
            $revenueDeltaAbsolute = '0.00';
            $revenueDeltaPercent = 0.0;
        }

        $profitDeltaAbsolute = null;
        $profitDeltaPercent = null;
        if ($totalProfit !== null && $previousProfit !== null) {
            if (bccomp($previousProfit, '0.00', 2) !== 0) {
                $profitDeltaAbsolute = bcsub($totalProfit, $previousProfit, 2);
                $profitDeltaPercent = (float) bcdiv(
                    bcmul($profitDeltaAbsolute, '100', 2),
                    $previousProfit,
                    2,
                );
            } elseif (bccomp($totalProfit, '0.00', 2) === 0) {
                $profitDeltaAbsolute = '0.00';
                $profitDeltaPercent = 0.0;
            }
        }

        return new PortfolioSummary(
            period: $period,
            totalRevenue: $totalRevenue,
            totalProfit: $totalProfit,
            marginPercent: $marginPercent,
            previousRevenue: $previousRevenue,
            previousProfit: $previousProfit,
            revenueDeltaAbsolute: $revenueDeltaAbsolute,
            revenueDeltaPercent: $revenueDeltaPercent,
            profitDeltaAbsolute: $profitDeltaAbsolute,
            profitDeltaPercent: $profitDeltaPercent,
        );
    }

    /**
     * @param ListingDailySnapshot[] $snapshots
     * @return array{0: string, 1: ?string}
     */
    private function computeTotals(array $snapshots): array
    {
        $totalRevenue = '0.00';
        $totalRefunds = '0.00';
        $totalCostPrice = '0.00';
        $totalCosts = '0.00';
        $allHaveCostPrice = true;

        foreach ($snapshots as $snapshot) {
            $totalRevenue = bcadd($totalRevenue, $snapshot->getRevenue(), 2);
            $totalRefunds = bcadd($totalRefunds, $snapshot->getRefunds(), 2);

            if ($snapshot->getCostPrice() === null) {
                $allHaveCostPrice = false;
            } else {
                $totalCostPrice = bcadd($totalCostPrice, $snapshot->getTotalCostPrice() ?? '0.00', 2);
            }

            $cb = CostBreakdown::fromArray($snapshot->getCostBreakdown());
            $totalCosts = bcadd($totalCosts, $cb->total(), 2);
        }

        $totalProfit = null;
        if ($allHaveCostPrice && !empty($snapshots)) {
            $totalProfit = bcsub(
                bcsub(bcsub($totalRevenue, $totalRefunds, 2), $totalCostPrice, 2),
                $totalCosts,
                2,
            );
        }

        return [$totalRevenue, $totalProfit];
    }
}
