<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Domain\Service;

use App\Marketplace\DTO\AdvertisingCostDTO;
use App\Marketplace\Enum\AdvertisingType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OrderStatus;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Domain\ValueObject\AdvertisingCpcMetrics;
use App\MarketplaceAnalytics\Domain\ValueObject\AdvertisingDetails;
use App\MarketplaceAnalytics\Domain\ValueObject\AdvertisingOtherMetrics;
use App\MarketplaceAnalytics\Domain\ValueObject\CostBreakdown;
use App\MarketplaceAnalytics\Domain\ValueObject\DataQualityFlags;
use App\MarketplaceAnalytics\Entity\ListingDailySnapshot;
use App\MarketplaceAnalytics\Enum\DataQualityFlag;
use App\MarketplaceAnalytics\Enum\UnitEconomyCostType;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use Ramsey\Uuid\Uuid;

final readonly class SnapshotCalculationPolicy
{
    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
        private CostMappingResolver $costMappingResolver,
        private ListingDailySnapshotRepositoryInterface $snapshotRepository,
    ) {}

    public function calculateForListingDay(
        string $companyId,
        string $listingId,
        \DateTimeImmutable $date,
    ): ListingDailySnapshot {
        $flags = DataQualityFlags::empty();

        // 1. Sales
        $sales = $this->marketplaceFacade->getSalesForListingAndDate($companyId, $listingId, $date);
        $revenue = '0.00';
        $salesQuantity = 0;
        $marketplace = null;

        foreach ($sales as $sale) {
            $revenue = bcadd($revenue, $sale->totalRevenue, 2);
            $salesQuantity += $sale->quantity;
            $marketplace = $sale->marketplace->value;
        }

        $avgSalePrice = bccomp($revenue, '0.00', 2) > 0 && $salesQuantity > 0
            ? bcdiv($revenue, (string) $salesQuantity, 2)
            : '0.00';

        // 2. Returns
        $returns = $this->marketplaceFacade->getReturnsForListingAndDate($companyId, $listingId, $date);
        $refunds = '0.00';
        $returnsQuantity = 0;

        foreach ($returns as $return) {
            $refunds = bcadd($refunds, $return->refundAmount, 2);
            $returnsQuantity += $return->quantity;
            if ($marketplace === null) {
                $marketplace = $return->marketplace->value;
            }
        }

        // 3. Cost price
        $costPrice = null;
        if (!empty($sales) && $sales[0]->rawData !== null && isset($sales[0]->rawData['cost_price'])) {
            $costPrice = (string) $sales[0]->rawData['cost_price'];
        }
        if ($costPrice === null) {
            $costPrice = $this->marketplaceFacade->getCostPriceForListing($companyId, $listingId, $date);
        }

        $totalCostPrice = null;
        if ($costPrice !== null) {
            $totalCostPrice = bcmul($costPrice, (string) $salesQuantity, 2);
        } else {
            $flags = $flags->addFlag(DataQualityFlag::COST_PRICE_MISSING);
        }

        // 4. Costs (non-advertising)
        $marketplace = $marketplace ?? 'wildberries';
        $costs = $this->marketplaceFacade->getCostsForListingAndDate($companyId, $listingId, $date);
        $costMap = [];

        foreach ($costs as $costDTO) {
            $type = $costDTO->categoryId !== null
                ? $this->costMappingResolver->resolve($companyId, $marketplace, $costDTO->categoryId)
                : UnitEconomyCostType::OTHER;
            if ($type->isAdvertising()) {
                continue;
            }
            $normalizedAmount = $this->normalizeAmountForCostBreakdown($costDTO->amount);
            $costMap[$type->value] = bcadd($costMap[$type->value] ?? '0.00', $normalizedAmount, 2);
        }

        // 5. Advertising
        $advCosts = $this->marketplaceFacade->getAdvertisingCostsForListingAndDate($companyId, $listingId, $date);

        $cpcSpend = '0.00';
        $cpcImpressions = 0;
        $cpcClicks = 0;
        $cpcOrders = 0;
        $cpcRevenue = '0.00';
        $otherSpend = '0.00';
        $otherDetails = [];

        foreach ($advCosts as $adv) {
            $normalizedAmount = $this->normalizeAmountForCostBreakdown($adv->amount);

            if ($adv->advertisingType === AdvertisingType::CPC) {
                $cpcSpend = bcadd($cpcSpend, $normalizedAmount, 2);
                $cpcImpressions += $adv->analyticsData['impressions'] ?? 0;
                $cpcClicks += $adv->analyticsData['clicks'] ?? 0;
                $cpcOrders += $adv->analyticsData['orders'] ?? 0;
                $cpcRevenue = bcadd($cpcRevenue, $adv->analyticsData['revenue'] ?? '0.00', 2);
            } else {
                $otherSpend = bcadd($otherSpend, $normalizedAmount, 2);
                $otherDetails[] = [
                    'type' => $adv->advertisingType->value,
                    'amount' => $normalizedAmount,
                    'campaign' => $adv->externalCampaignId,
                ];
            }
        }

        $ctr = $cpcImpressions > 0 ? (float) bcdiv(bcmul((string) $cpcClicks, '100', 2), (string) $cpcImpressions, 2) : 0.0;
        $cr = $cpcClicks > 0 ? (float) bcdiv(bcmul((string) $cpcOrders, '100', 2), (string) $cpcClicks, 2) : 0.0;
        $cpc = $cpcClicks > 0 ? bcdiv($cpcSpend, (string) $cpcClicks, 2) : '0.00';
        $cpm = $cpcImpressions > 0 ? bcdiv(bcmul($cpcSpend, '1000', 2), (string) $cpcImpressions, 2) : '0.00';
        $cpo = $cpcOrders > 0 ? bcdiv($cpcSpend, (string) $cpcOrders, 2) : '0.00';
        $acos = bccomp($cpcRevenue, '0.00', 2) > 0
            ? bcdiv(bcmul($cpcSpend, '100', 2), $cpcRevenue, 2)
            : '0.00';

        $cpcMetrics = new AdvertisingCpcMetrics(
            spend: $cpcSpend,
            impressions: $cpcImpressions,
            clicks: $cpcClicks,
            ctr: $ctr,
            cpc: $cpc,
            cpm: $cpm,
            orders: $cpcOrders,
            cpo: $cpo,
            revenue: $cpcRevenue,
            cr: $cr,
            acos: $acos,
        );

        $otherMetrics = new AdvertisingOtherMetrics(
            spend: $otherSpend,
            details: $otherDetails,
        );

        $advertisingDetails = new AdvertisingDetails($cpcMetrics, $otherMetrics);

        if ($cpcMetrics->isEmpty() && $otherMetrics->isEmpty()) {
            $flags = $flags->addFlag(DataQualityFlag::API_ADVERTISING_MISSING);
        }

        $costMap['advertising_cpc'] = $cpcMetrics->spend;
        $costMap['advertising_other'] = $otherMetrics->spend;
        $costBreakdown = CostBreakdown::fromArray($costMap);

        // 6. Orders
        $orders = $this->marketplaceFacade->getOrdersForListingAndDate($companyId, $listingId, $date);
        $ordersQuantity = count($orders);
        $deliveredQuantity = 0;
        foreach ($orders as $order) {
            if ($order->status === OrderStatus::DELIVERED) {
                $deliveredQuantity++;
            }
        }

        // 7. DataQuality flags already built above

        // 8. Upsert
        $snapshot = $this->snapshotRepository->findOneByUniqueKey($companyId, $listingId, $date);
        if ($snapshot === null) {
            $snapshot = new ListingDailySnapshot(
                Uuid::uuid7()->toString(),
                $companyId,
                $listingId,
                MarketplaceType::from($marketplace),
                $date,
            );
        }

        $snapshot->recalculate(
            $revenue,
            $refunds,
            $salesQuantity,
            $returnsQuantity,
            $ordersQuantity,
            $deliveredQuantity,
            $avgSalePrice,
            $costPrice,
            $totalCostPrice,
            $costBreakdown->toArray(),
            $advertisingDetails->toArray(),
            $flags->toArray(),
        );

        $this->snapshotRepository->save($snapshot);

        return $snapshot;
    }

    private function normalizeAmountForCostBreakdown(string $amount): string
    {
        if (bccomp($amount, '0.00', 2) < 0) {
            return bcmul($amount, '-1', 2);
        }

        return $amount;
    }
}
