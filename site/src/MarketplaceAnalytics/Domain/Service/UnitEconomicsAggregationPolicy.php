<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\MarketplaceAnalytics\Domain\ValueObject\AnalysisPeriod;
use App\MarketplaceAnalytics\Domain\ValueObject\CostBreakdown;
use App\MarketplaceAnalytics\Domain\ValueObject\DataQualityFlags;
use App\MarketplaceAnalytics\DTO\ListingUnitEconomics;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;

final readonly class UnitEconomicsAggregationPolicy
{
    public function __construct(
        private ListingDailySnapshotRepositoryInterface $snapshotRepository,
    ) {}

    /**
     * @return ListingUnitEconomics[]
     */
    public function aggregateForPeriod(
        string $companyId,
        AnalysisPeriod $period,
        ?string $marketplace,
    ): array {
        $snapshots = $this->snapshotRepository->findByCompanyAndPeriod(
            $companyId,
            $period->dateFrom,
            $period->dateTo,
            $marketplace,
        );

        if (empty($snapshots)) {
            return [];
        }

        $grouped = [];
        foreach ($snapshots as $snapshot) {
            $grouped[$snapshot->getListingId()][] = $snapshot;
        }

        $result = [];
        foreach ($grouped as $listingId => $listingSnapshots) {
            $result[] = $this->aggregateGroup($listingId, $listingSnapshots, $period);
        }

        usort($result, static fn(ListingUnitEconomics $a, ListingUnitEconomics $b) =>
            (int) $b->hasCostPrice() <=> (int) $a->hasCostPrice(),
        );

        return $result;
    }

    /**
     * @param ListingDailySnapshot[] $snapshots
     */
    private function aggregateGroup(string $listingId, array $snapshots, AnalysisPeriod $period): ListingUnitEconomics
    {
        $first = $snapshots[0];

        $revenue = '0.00';
        $refunds = '0.00';
        $salesQuantity = 0;
        $returnsQuantity = 0;
        $ordersQuantity = 0;
        $deliveredQuantity = 0;

        $logisticsTo = '0.00';
        $logisticsBack = '0.00';
        $storage = '0.00';
        $advertisingCpc = '0.00';
        $advertisingOther = '0.00';
        $advertisingExternal = '0.00';
        $commission = '0.00';
        $otherCosts = '0.00';

        $totalCostPrice = '0.00';
        $hasCostPrice = true;
        $weightedPriceSum = '0.00';
        $weightedCostSum = '0.00';
        $totalQtyForAvg = 0;

        $impressions = 0;
        $clicks = 0;
        $advOrders = 0;
        $advSpend = '0.00';
        $advRevenue = '0.00';

        $dataQuality = DataQualityFlags::empty();

        foreach ($snapshots as $snapshot) {
            $revenue = bcadd($revenue, $snapshot->getRevenue(), 2);
            $refunds = bcadd($refunds, $snapshot->getRefunds(), 2);
            $salesQuantity += $snapshot->getSalesQuantity();
            $returnsQuantity += $snapshot->getReturnsQuantity();
            $ordersQuantity += $snapshot->getOrdersQuantity();
            $deliveredQuantity += $snapshot->getDeliveredQuantity();

            $cb = CostBreakdown::fromArray($snapshot->getCostBreakdown());
            $logisticsTo = bcadd($logisticsTo, $cb->logisticsTo, 2);
            $logisticsBack = bcadd($logisticsBack, $cb->logisticsBack, 2);
            $storage = bcadd($storage, $cb->storage, 2);
            $advertisingCpc = bcadd($advertisingCpc, $cb->advertisingCpc, 2);
            $advertisingOther = bcadd($advertisingOther, $cb->advertisingOther, 2);
            $advertisingExternal = bcadd($advertisingExternal, $cb->advertisingExternal, 2);
            $commission = bcadd($commission, $cb->commission, 2);
            $otherCosts = bcadd($otherCosts, $cb->other, 2);

            if ($snapshot->getCostPrice() === null) {
                $hasCostPrice = false;
            } else {
                $totalCostPrice = bcadd($totalCostPrice, $snapshot->getTotalCostPrice() ?? '0.00', 2);
                $weightedCostSum = bcadd(
                    $weightedCostSum,
                    bcmul($snapshot->getCostPrice(), (string) $snapshot->getSalesQuantity(), 2),
                    2,
                );
            }

            $qty = $snapshot->getSalesQuantity();
            if ($qty > 0) {
                $weightedPriceSum = bcadd(
                    $weightedPriceSum,
                    bcmul($snapshot->getAvgSalePrice(), (string) $qty, 2),
                    2,
                );
                $totalQtyForAvg += $qty;
            }

            $ad = $snapshot->getAdvertisingDetails();
            if (isset($ad['cpc'])) {
                $impressions += $ad['cpc']['impressions'] ?? 0;
                $clicks += $ad['cpc']['clicks'] ?? 0;
                $advOrders += $ad['cpc']['orders'] ?? 0;
                $advSpend = bcadd($advSpend, $ad['cpc']['spend'] ?? '0.00', 2);
                $advRevenue = bcadd($advRevenue, $ad['cpc']['revenue'] ?? '0.00', 2);
            }

            $snapshotFlags = DataQualityFlags::fromArray($snapshot->getDataQuality());
            $dataQuality = array_reduce(
                $snapshotFlags->flags,
                static fn(DataQualityFlags $carry, $flag) => $carry->addFlag($flag),
                $dataQuality,
            );
        }

        $avgSalePrice = $totalQtyForAvg > 0
            ? bcdiv($weightedPriceSum, (string) $totalQtyForAvg, 2)
            : '0.00';

        $aggCostPrice = null;
        if ($hasCostPrice && $totalQtyForAvg > 0) {
            $aggCostPrice = bcdiv($weightedCostSum, (string) $totalQtyForAvg, 2);
        }

        $totalCosts = '0.00';
        $totalCosts = bcadd($totalCosts, $logisticsTo, 2);
        $totalCosts = bcadd($totalCosts, $logisticsBack, 2);
        $totalCosts = bcadd($totalCosts, $storage, 2);
        $totalCosts = bcadd($totalCosts, $advertisingCpc, 2);
        $totalCosts = bcadd($totalCosts, $advertisingOther, 2);
        $totalCosts = bcadd($totalCosts, $advertisingExternal, 2);
        $totalCosts = bcadd($totalCosts, $commission, 2);
        $totalCosts = bcadd($totalCosts, $otherCosts, 2);

        $totalAdvertising = bcadd($advertisingCpc, bcadd($advertisingOther, $advertisingExternal, 2), 2);

        $ctr = $impressions > 0 ? (float) bcdiv(bcmul((string) $clicks, '100', 2), (string) $impressions, 2) : null;
        $cr = $clicks > 0 ? (float) bcdiv(bcmul((string) $advOrders, '100', 2), (string) $clicks, 2) : null;
        $cpo = $advOrders > 0 ? bcdiv($advSpend, (string) $advOrders, 2) : null;
        $acos = bccomp($advRevenue, '0.00', 2) > 0
            ? bcdiv(bcmul($advSpend, '100', 2), $advRevenue, 2)
            : null;

        $profitPerUnit = null;
        $profitTotal = null;
        $drr = null;
        $roi = null;
        $ros = null;
        $purchaseRate = null;

        if ($aggCostPrice !== null) {
            if ($salesQuantity > 0) {
                $profitPerUnit = bcsub(
                    bcsub($avgSalePrice, $aggCostPrice, 2),
                    bcdiv($totalCosts, (string) $salesQuantity, 2),
                    2,
                );
            }

            $profitTotal = bcsub(
                bcsub(bcsub($revenue, $refunds, 2), $hasCostPrice ? $totalCostPrice : '0.00', 2),
                $totalCosts,
                2,
            );

            if (bccomp($revenue, '0.00', 2) > 0) {
                $drr = (float) bcdiv(bcmul($totalAdvertising, '100', 2), $revenue, 2);
                $ros = (float) bcdiv(bcmul($profitTotal, '100', 2), $revenue, 2);
            }

            $investmentBase = bcadd($totalCostPrice, $totalCosts, 2);
            if (bccomp($investmentBase, '0.00', 2) > 0) {
                $roi = (float) bcdiv(bcmul($profitTotal, '100', 2), $investmentBase, 2);
            }
        }

        if ($ordersQuantity > 0) {
            $purchaseRate = (float) bcdiv(
                bcmul((string) $deliveredQuantity, '100', 2),
                (string) $ordersQuantity,
                2,
            );
        }

        return new ListingUnitEconomics(
            listingId: $listingId,
            listingName: null,
            marketplaceSku: '',
            marketplaceType: $first->getMarketplace()->value,
            period: $period,
            revenue: $revenue,
            refunds: $refunds,
            avgSalePrice: $avgSalePrice,
            costPrice: $aggCostPrice,
            logisticsTo: $logisticsTo,
            logisticsBack: $logisticsBack,
            storage: $storage,
            advertisingCpc: $advertisingCpc,
            advertisingOther: $advertisingOther,
            advertisingExternal: $advertisingExternal,
            commission: $commission,
            otherCosts: $otherCosts,
            advertisingImpressions: $impressions,
            advertisingClicks: $clicks,
            advertisingCtr: $ctr,
            advertisingCr: $cr,
            advertisingCpo: $cpo,
            advertisingAcos: $acos,
            purchaseRate: $purchaseRate,
            profitPerUnit: $profitPerUnit,
            profitTotal: $profitTotal,
            drr: $drr,
            roi: $roi,
            ros: $ros,
            gmroi: null,
            salesQuantity: $salesQuantity,
            returnsQuantity: $returnsQuantity,
            ordersQuantity: $ordersQuantity,
            deliveredQuantity: $deliveredQuantity,
            dataQuality: $dataQuality,
        );
    }
}
