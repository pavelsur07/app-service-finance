<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Facade\MarketplaceAdsFacade;
use App\MarketplaceAnalytics\Application\Service\MarketplaceCostAnalyticsGroupResolver;

final readonly class UnitExtendedQuery
{
    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
        private MarketplaceAdsFacade $adsFacade,
        private MarketplaceCostAnalyticsGroupResolver $groupResolver,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function execute(
        string $companyId,
        ?string $marketplace,
        string $periodFrom,
        string $periodTo,
        int $limit = 500,
    ): array {
        $from = new \DateTimeImmutable($periodFrom);
        $to = new \DateTimeImmutable($periodTo);

        $sales = $this->marketplaceFacade->getSalesAggregatesByListing($companyId, $marketplace, $from, $to);
        $returns = $this->marketplaceFacade->getReturnAggregatesByListing($companyId, $marketplace, $from, $to);
        $costs = $this->marketplaceFacade->getCostAggregatesByListing($companyId, $marketplace, $from, $to);

        // Per-listing ad spend (only attributed). Listings present here but absent from
        // sales/returns/costs are intentionally NOT merged into $allListingIds — the row
        // would be empty otherwise; their spend is still counted in totals via
        // getTotalAdCostForPeriod() below (which includes non-attributed too).
        $adSpendByListing = $this->adsFacade->getAdSpendByListingForPeriod(
            $companyId,
            $from,
            $to,
            $marketplace,
        );

        // Merge all unique listing IDs from three sources so listings
        // with only returns or costs are not lost
        $allListingIds = array_unique(array_merge(
            array_keys($sales),
            array_keys($returns),
            array_keys($costs),
        ));

        // Fetch metadata (title, sku, marketplace) for listings not present in sales
        $nonSalesIds = array_values(array_diff($allListingIds, array_keys($sales)));
        $listingMeta = $this->marketplaceFacade->getListingsMetaByIds($companyId, $nonSalesIds);

        $items = [];
        $totals = [
            'revenue' => 0.0,
            'quantity' => 0,
            'returnsTotal' => 0.0,
            'costPriceTotal' => 0.0,
            'commission' => 0.0,
            'adSpend' => 0.0,
            'logistics' => 0.0,
            'otherCosts' => 0.0,
            'totalCosts' => 0.0,
            'profit' => 0.0,
        ];

        foreach ($allListingIds as $listingId) {
            $sale = $sales[$listingId] ?? null;
            $ret = $returns[$listingId] ?? null;
            $listingCosts = $costs[$listingId] ?? [];
            $meta = $listingMeta[$listingId] ?? null;

            $revenue = $sale !== null ? (float) $sale->revenue : 0.0;
            $quantity = $sale !== null ? $sale->quantity : 0;
            $returnsTotal = $ret !== null ? (float) $ret->returnsTotal : 0.0;
            $costPriceTotal = $sale !== null ? (float) $sale->costPriceTotal : 0.0;
            $costPriceQuantity = $sale !== null ? $sale->costPriceQuantity : 0;
            $costPriceUnit = $costPriceQuantity > 0
                ? round($costPriceTotal / $costPriceQuantity, 2)
                : 0.0;

            // Listing metadata: prefer sales source, fallback to listings table
            $title = $sale?->title ?? $meta?->title ?? '';
            $sku = $sale?->sku ?? $meta?->sku ?? '';
            $mp = $sale?->marketplace ?? $meta?->marketplace ?? '';
            $sellerArticle = $sale?->supplierSku ?? $meta?->supplierSku ?? '';

            // Classify costs
            $commission = 0.0;
            $logistics = 0.0;
            $otherCosts = 0.0;
            $allCategoriesRaw = [];

            foreach ($listingCosts as $cat) {
                $code = $cat->categoryCode;
                $net = (float) $cat->netAmount;
                $costsAmt = (float) $cat->costsAmount;
                $stornoAmt = (float) $cat->stornoAmount;

                $costMarketplace = $mp !== '' ? $mp : $marketplace;

                $allCategoriesRaw[] = [
                    'marketplace' => $costMarketplace !== '' ? $costMarketplace : null,
                    'code' => $code,
                    'name' => $cat->categoryName,
                    'costsAmount' => round($costsAmt, 2),
                    'stornoAmount' => round($stornoAmt, 2),
                    'netAmount' => round($net, 2),
                ];

                $unitBucket = $this->groupResolver->resolveUnitBucket(
                    $costMarketplace !== '' ? $costMarketplace : null,
                    $code,
                    $cat->categoryName,
                );

                if ($unitBucket === 'commission') {
                    $commission += $net;
                } elseif ($unitBucket === 'logistics') {
                    $logistics += $net;
                } else {
                    $otherCosts += $net;
                }
            }

            $commission = round($commission, 2);
            $logistics = round($logistics, 2);
            $otherCosts = round($otherCosts, 2);
            $adSpend = round((float) ($adSpendByListing[$listingId] ?? '0'), 2);
            $totalCostsVal = round($commission + $logistics + $otherCosts + $adSpend, 2);
            $profit = round(
                $revenue - $returnsTotal - $costPriceTotal
                - $commission - $logistics - $otherCosts - $adSpend,
                2,
            );
            $drrPercent = $revenue > 0 ? round($adSpend / $revenue * 100, 1) : null;

            // Build breakdown grouped by serviceGroup
            $otherBreakdown = $this->buildBreakdown($allCategoriesRaw, true);
            $allBreakdown = $this->buildBreakdown($allCategoriesRaw, false);

            $items[] = [
                'listingId' => $listingId,
                'title' => $title,
                'sku' => $sku,
                'sellerArticle' => $sellerArticle,
                'marketplace' => $mp,
                'revenue' => round($revenue, 2),
                'quantity' => $quantity,
                'returnsTotal' => round($returnsTotal, 2),
                'costPriceTotal' => round($costPriceTotal, 2),
                'costPriceUnit' => $costPriceUnit,
                'commission' => $commission,
                'adSpend' => $adSpend,
                'drrPercent' => $drrPercent,
                'logistics' => $logistics,
                'otherCosts' => $otherCosts,
                'totalCosts' => $totalCostsVal,
                'profit' => $profit,
                'marginPercent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'roiPercent' => $costPriceTotal > 0 ? round($profit / $costPriceTotal * 100, 1) : null,
                'otherCostsBreakdown' => $otherBreakdown,
                'allCostsBreakdown' => $allBreakdown,
            ];

            // Totals accumulate across ALL listings (not limited).
            // adSpend/totalCosts/profit will be recomputed below from getTotalAdCostForPeriod()
            // — sum of per-row adSpend may be < totals.adSpend by design (rows show only
            // attributed ad spend; totals include non-attributed for parity with
            // /marketplace-ads/efficiency).
            $totals['revenue'] += $revenue;
            $totals['quantity'] += $quantity;
            $totals['returnsTotal'] += $returnsTotal;
            $totals['costPriceTotal'] += $costPriceTotal;
            $totals['commission'] += $commission;
            $totals['logistics'] += $logistics;
            $totals['otherCosts'] += $otherCosts;
        }

        // Sort by revenue DESC
        usort($items, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        // Round totals (computed from ALL listings before limit)
        foreach ($totals as $key => $val) {
            $totals[$key] = is_float($val) ? round($val, 2) : $val;
        }

        // totals.adSpend — full ad cost for the period (including non-attributed),
        // for parity with /marketplace-ads/efficiency. Recompute totalCosts/profit
        // accordingly. Sum of per-row adSpend may be smaller — that is OK.
        $totalAdSpend = round((float) $this->adsFacade->getTotalAdCostForPeriod(
            $companyId,
            $from,
            $to,
            $marketplace,
        ), 2);

        $totals['adSpend'] = $totalAdSpend;
        $totals['drrPercent'] = $totals['revenue'] > 0
            ? round($totalAdSpend / $totals['revenue'] * 100, 1)
            : null;
        $totals['totalCosts'] = round(
            $totals['commission'] + $totals['logistics'] + $totals['otherCosts'] + $totalAdSpend,
            2,
        );
        $totals['profit'] = round(
            $totals['revenue'] - $totals['returnsTotal'] - $totals['costPriceTotal']
            - $totals['commission'] - $totals['logistics'] - $totals['otherCosts'] - $totalAdSpend,
            2,
        );
        $totals['marginPercent'] = $totals['revenue'] > 0
            ? round($totals['profit'] / $totals['revenue'] * 100, 1)
            : null;
        $totals['roiPercent'] = $totals['costPriceTotal'] > 0
            ? round($totals['profit'] / $totals['costPriceTotal'] * 100, 1)
            : null;

        // Limit items for response; totals remain complete
        $items = \array_slice($items, 0, $limit);

        return ['items' => $items, 'totals' => $totals];
    }

    /**
     * @param list<array{marketplace: ?string, code: string, name: string, costsAmount: float, stornoAmount: float, netAmount: float}> $categories
     * @return list<array<string, mixed>>
     */
    private function buildBreakdown(array $categories, bool $excludeUnitColumns): array
    {
        $grouped = [];
        foreach ($categories as $cat) {
            $marketplace = is_string($cat['marketplace']) ? $cat['marketplace'] : null;

            if ($excludeUnitColumns) {
                $unitBucket = $this->groupResolver->resolveUnitBucket($marketplace, $cat['code'], $cat['name']);
                if ($unitBucket === 'commission' || $unitBucket === 'logistics') {
                    continue;
                }
            }

            $group = $this->groupResolver->resolveBreakdownGroup($marketplace, $cat['code'], $cat['name']);

            if (!isset($grouped[$group])) {
                $grouped[$group] = [
                    'serviceGroup' => $group,
                    'costsAmount' => 0.0,
                    'stornoAmount' => 0.0,
                    'netAmount' => 0.0,
                    'categories' => [],
                ];
            }

            $grouped[$group]['costsAmount'] += $cat['costsAmount'];
            $grouped[$group]['stornoAmount'] += $cat['stornoAmount'];
            $grouped[$group]['netAmount'] += $cat['netAmount'];
            $categoryForResponse = $cat;
            unset($categoryForResponse['marketplace']);
            $grouped[$group]['categories'][] = $categoryForResponse;
        }

        $result = [];
        foreach ($grouped as $group) {
            $group['costsAmount'] = round($group['costsAmount'], 2);
            $group['stornoAmount'] = round($group['stornoAmount'], 2);
            $group['netAmount'] = round($group['netAmount'], 2);
            $result[] = $group;
        }

        usort($result, static fn (array $a, array $b): int => $b['netAmount'] <=> $a['netAmount']);

        return $result;
    }
}
