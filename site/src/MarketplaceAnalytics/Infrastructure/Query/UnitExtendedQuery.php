<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Application\Reconciliation\OzonXlsxServiceGroupMap;
use App\Marketplace\Facade\MarketplaceFacade;

final readonly class UnitExtendedQuery
{
    /** Category codes considered as "commission" */
    private const COMMISSION_CODES = [
        'ozon_sale_commission',
        'ozon_brand_commission',
    ];

    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
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

        $categoryToGroup = OzonXlsxServiceGroupMap::getCategoryToServiceGroup();
        $logisticsCodes = $this->getLogisticsCodes($categoryToGroup);

        $items = [];
        $totals = [
            'revenue' => 0.0,
            'quantity' => 0,
            'returnsTotal' => 0.0,
            'costPriceTotal' => 0.0,
            'commission' => 0.0,
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

                $allCategoriesRaw[] = [
                    'code' => $code,
                    'name' => $cat->categoryName,
                    'costsAmount' => round($costsAmt, 2),
                    'stornoAmount' => round($stornoAmt, 2),
                    'netAmount' => round($net, 2),
                ];

                if (in_array($code, self::COMMISSION_CODES, true)) {
                    $commission += $net;
                } elseif (in_array($code, $logisticsCodes, true)) {
                    $logistics += $net;
                } else {
                    $otherCosts += $net;
                }
            }

            $commission = round($commission, 2);
            $logistics = round($logistics, 2);
            $otherCosts = round($otherCosts, 2);
            $totalCostsVal = round($commission + $logistics + $otherCosts, 2);
            $profit = round($revenue - $returnsTotal - $costPriceTotal - $commission - $logistics - $otherCosts, 2);

            // Build breakdown grouped by serviceGroup
            $otherBreakdown = $this->buildBreakdown($allCategoriesRaw, $categoryToGroup, $logisticsCodes);
            $allBreakdown = $this->buildBreakdown($allCategoriesRaw, $categoryToGroup, []);

            $items[] = [
                'listingId' => $listingId,
                'title' => $title,
                'sku' => $sku,
                'marketplace' => $mp,
                'revenue' => round($revenue, 2),
                'quantity' => $quantity,
                'returnsTotal' => round($returnsTotal, 2),
                'costPriceTotal' => round($costPriceTotal, 2),
                'costPriceUnit' => $costPriceUnit,
                'commission' => $commission,
                'logistics' => $logistics,
                'otherCosts' => $otherCosts,
                'totalCosts' => $totalCostsVal,
                'profit' => $profit,
                'marginPercent' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
                'roiPercent' => $costPriceTotal > 0 ? round($profit / $costPriceTotal * 100, 1) : null,
                'otherCostsBreakdown' => $otherBreakdown,
                'allCostsBreakdown' => $allBreakdown,
            ];

            // Totals accumulate across ALL listings (not limited)
            $totals['revenue'] += $revenue;
            $totals['quantity'] += $quantity;
            $totals['returnsTotal'] += $returnsTotal;
            $totals['costPriceTotal'] += $costPriceTotal;
            $totals['commission'] += $commission;
            $totals['logistics'] += $logistics;
            $totals['otherCosts'] += $otherCosts;
            $totals['totalCosts'] += $totalCostsVal;
            $totals['profit'] += $profit;
        }

        // Sort by revenue DESC
        usort($items, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        // Round totals (computed from ALL listings before limit)
        foreach ($totals as $key => $val) {
            $totals[$key] = is_float($val) ? round($val, 2) : $val;
        }

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
     * @param list<array{code: string, name: string, costsAmount: float, stornoAmount: float, netAmount: float}> $categories
     * @param array<string, string> $categoryToGroup
     * @param list<string> $excludeCodes codes to exclude (logistics for "other" breakdown)
     * @return list<array<string, mixed>>
     */
    private function buildBreakdown(array $categories, array $categoryToGroup, array $excludeCodes): array
    {
        $commissionCodes = self::COMMISSION_CODES;

        $grouped = [];
        foreach ($categories as $cat) {
            // For "other" breakdown: skip commission and logistics
            if (!empty($excludeCodes)) {
                if (in_array($cat['code'], $commissionCodes, true) || in_array($cat['code'], $excludeCodes, true)) {
                    continue;
                }
            }

            $group = $categoryToGroup[$cat['code']] ?? 'Прочее';

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
            $grouped[$group]['categories'][] = $cat;
        }

        // Round and sort
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

    /**
     * @param array<string, string> $categoryToGroup
     * @return list<string>
     */
    private function getLogisticsCodes(array $categoryToGroup): array
    {
        $codes = [];
        foreach ($categoryToGroup as $code => $group) {
            if ($group === 'Услуги доставки') {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
