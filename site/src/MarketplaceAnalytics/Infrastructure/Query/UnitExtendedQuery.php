<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Application\Reconciliation\OzonXlsxServiceGroupMap;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class UnitExtendedQuery
{
    /** Category codes considered as "commission" */
    private const COMMISSION_CODES = [
        'ozon_sale_commission',
        'ozon_brand_commission',
    ];

    public function __construct(
        private Connection $connection,
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
        $sales = $this->fetchSales($companyId, $marketplace, $periodFrom, $periodTo);
        $returns = $this->fetchReturns($companyId, $marketplace, $periodFrom, $periodTo);
        $costs = $this->fetchCosts($companyId, $marketplace, $periodFrom, $periodTo);

        // Merge all unique listing IDs from three sources so listings
        // with only returns or costs are not lost
        $allListingIds = array_unique(array_merge(
            array_keys($sales),
            array_keys($returns),
            array_keys($costs),
        ));

        // Fetch metadata (title, sku, marketplace) for listings not present in sales
        $nonSalesIds = array_diff($allListingIds, array_keys($sales));
        $listingMeta = $this->fetchListingMeta($nonSalesIds);

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

            $revenue = $sale !== null ? (float) $sale['revenue'] : 0.0;
            $quantity = $sale !== null ? (int) $sale['quantity'] : 0;
            $returnsTotal = $ret !== null ? (float) $ret['returns_total'] : 0.0;
            $costPriceTotal = $sale !== null ? (float) $sale['cost_price_total'] : 0.0;
            $costPriceQuantity = $sale !== null ? (int) $sale['cost_price_quantity'] : 0;
            $costPriceUnit = $costPriceQuantity > 0
                ? round($costPriceTotal / $costPriceQuantity, 2)
                : 0.0;

            // Listing metadata: prefer sales source, fallback to listings table
            $title = $sale['listing_title'] ?? $meta['listing_title'] ?? '';
            $sku = $sale['listing_sku'] ?? $meta['listing_sku'] ?? '';
            $mp = $sale['listing_marketplace'] ?? $meta['listing_marketplace'] ?? '';

            // Classify costs
            $commission = 0.0;
            $logistics = 0.0;
            $otherCosts = 0.0;
            $allCategoriesRaw = [];

            foreach ($listingCosts as $cat) {
                $code = $cat['category_code'];
                $net = (float) $cat['net_amount'];
                $costsAmt = (float) $cat['costs_amount'];
                $stornoAmt = (float) $cat['storno_amount'];

                $allCategoriesRaw[] = [
                    'code' => $code,
                    'name' => $cat['category_name'],
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

        // Limit items for response; totals remain complete
        $items = \array_slice($items, 0, $limit);

        return ['items' => $items, 'totals' => $totals];
    }

    /**
     * @return array<string, array<string, mixed>> listingId → row
     */
    private function fetchSales(string $companyId, ?string $marketplace, string $periodFrom, string $periodTo): array
    {
        $mpFilter = $marketplace !== null ? 'AND s.marketplace = :marketplace' : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                s.listing_id,
                l.name                AS listing_title,
                l.marketplace_sku     AS listing_sku,
                l.marketplace         AS listing_marketplace,
                SUM(s.total_revenue)  AS revenue,
                SUM(s.quantity)       AS quantity,
                SUM(CASE WHEN s.cost_price IS NOT NULL THEN s.cost_price * s.quantity ELSE 0 END) AS cost_price_total,
                SUM(CASE WHEN s.cost_price IS NOT NULL THEN s.quantity ELSE 0 END) AS cost_price_quantity
            FROM marketplace_sales s
            JOIN marketplace_listings l ON l.id = s.listing_id
            WHERE s.company_id = :companyId
              AND s.sale_date >= :periodFrom
              AND s.sale_date <= :periodTo
              {$mpFilter}
            GROUP BY s.listing_id, l.name, l.marketplace_sku, l.marketplace
            SQL,
            array_filter([
                'companyId' => $companyId,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                'marketplace' => $marketplace,
            ], static fn ($v) => $v !== null),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']] = $row;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>> listingId → row
     */
    private function fetchReturns(string $companyId, ?string $marketplace, string $periodFrom, string $periodTo): array
    {
        $mpFilter = $marketplace !== null ? 'AND r.marketplace = :marketplace' : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                r.listing_id,
                SUM(r.refund_amount) AS returns_total,
                SUM(r.quantity)      AS returns_quantity
            FROM marketplace_returns r
            WHERE r.company_id = :companyId
              AND r.return_date >= :periodFrom
              AND r.return_date <= :periodTo
              {$mpFilter}
            GROUP BY r.listing_id
            SQL,
            array_filter([
                'companyId' => $companyId,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                'marketplace' => $marketplace,
            ], static fn ($v) => $v !== null),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']] = $row;
        }

        return $result;
    }

    /**
     * @return array<string, list<array<string, mixed>>> listingId → list of category rows
     */
    private function fetchCosts(string $companyId, ?string $marketplace, string $periodFrom, string $periodTo): array
    {
        $mpFilter = $marketplace !== null ? 'AND c.marketplace = :marketplace' : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                c.listing_id,
                cc.code                                                        AS category_code,
                cc.name                                                        AS category_name,
                SUM(c.amount)                                                  AS net_amount,
                SUM(CASE WHEN c.amount > 0 THEN c.amount ELSE 0 END)         AS costs_amount,
                SUM(CASE WHEN c.amount < 0 THEN ABS(c.amount) ELSE 0 END)   AS storno_amount
            FROM marketplace_costs c
            JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id = :companyId
              AND c.cost_date >= :periodFrom
              AND c.cost_date <= :periodTo
              AND c.listing_id IS NOT NULL
              {$mpFilter}
            GROUP BY c.listing_id, cc.code, cc.name
            SQL,
            array_filter([
                'companyId' => $companyId,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                'marketplace' => $marketplace,
            ], static fn ($v) => $v !== null),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['listing_id']][] = $row;
        }

        return $result;
    }

    /**
     * Fetch listing metadata (title, sku, marketplace) for listings not present in the sales result.
     *
     * @param list<string> $listingIds
     * @return array<string, array{listing_title: string, listing_sku: string, listing_marketplace: string}>
     */
    private function fetchListingMeta(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                l.id,
                l.name           AS listing_title,
                l.marketplace_sku AS listing_sku,
                l.marketplace    AS listing_marketplace
            FROM marketplace_listings l
            WHERE l.id IN (:ids)
            SQL,
            ['ids' => array_values($listingIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['id']] = $row;
        }

        return $result;
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
