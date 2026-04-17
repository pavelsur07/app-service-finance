<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Application\Service\WidgetServiceGroupMap;
use Doctrine\DBAL\Connection;

/**
 * Сводка для виджета MarketplaceAnalytics за период.
 *
 * Все суммы возвращаются в P&L-конвенции (как в Ozon ЛК):
 *   доходы > 0, расходы < 0, profit = revenue + returnsTotal + costPriceTotal + totalCosts.
 *
 * Затраты берутся напрямую из marketplace_costs БЕЗ фильтра listing_id IS NOT NULL,
 * чтобы захватить категории, не привязанные к листингу (CPC, хранение, кросс-докинг и т.п.).
 * Sales/returns остаются per-listing через MarketplaceFacade — они всегда
 * привязаны к товарам.
 */
final readonly class WidgetSummaryQuery
{
    private const FALLBACK_GROUP = 'Другие услуги и штрафы';

    /** @var list<string> */
    private const WIDGET_GROUPS = [
        'Вознаграждение',
        'Услуги доставки и FBO',
        'Услуги партнёров',
        'Продвижение и реклама',
        'Другие услуги и штрафы',
    ];

    public function __construct(
        private MarketplaceFacade $marketplaceFacade,
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     revenue: float,
     *     returnsTotal: float,
     *     costPriceTotal: float,
     *     totalCosts: float,
     *     profit: float,
     *     marginPercent: float|null,
     *     widgetGroups: list<array<string, mixed>>,
     * }
     */
    public function getSummary(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array {
        $sales = $this->marketplaceFacade->getSalesAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);
        $returns = $this->marketplaceFacade->getReturnAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);
        $costRows = $this->getCostAggregates($companyId, $marketplace, $dateFrom, $dateTo);

        $codeToGroup = WidgetServiceGroupMap::getCategoryToWidgetGroup();

        $revenue = 0.0;
        $returnsTotal = 0.0;
        $costPriceTotal = 0.0;

        $groups = [];
        foreach (self::WIDGET_GROUPS as $groupName) {
            $groups[$groupName] = [
                'serviceGroup' => $groupName,
                'costsAmount'  => 0.0,
                'stornoAmount' => 0.0,
                'netAmount'    => 0.0,
                'categories'   => [],
            ];
        }

        foreach ($sales as $sale) {
            $revenue += (float) $sale->revenue;
            $costPriceTotal -= (float) $sale->costPriceTotal;
        }

        foreach ($returns as $ret) {
            $returnsTotal -= (float) $ret->returnsTotal;
        }

        // Costs — плоский список категорий (без листингов).
        // Включает категории с listing_id = NULL (CPC, хранение и т.п.).
        foreach ($costRows as $row) {
            $code = (string) $row['category_code'];
            $name = (string) $row['category_name'];
            $costsAmt = (float) $row['costs_amount'];
            $stornoAmt = (float) $row['storno_amount'];
            $netAmt = (float) $row['net_amount'];

            $group = $codeToGroup[$code] ?? self::FALLBACK_GROUP;

            $groups[$group]['costsAmount'] += $costsAmt;
            $groups[$group]['stornoAmount'] += $stornoAmt;
            $groups[$group]['netAmount'] += $netAmt;

            // Агрегируем одинаковые categoryCode внутри группы
            // (на всякий случай — SQL уже группирует по cc.code, cc.name)
            if (!isset($groups[$group]['categories'][$code])) {
                $groups[$group]['categories'][$code] = [
                    'code'         => $code,
                    'name'         => $name,
                    'costsAmount'  => 0.0,
                    'stornoAmount' => 0.0,
                    'netAmount'    => 0.0,
                ];
            }

            $groups[$group]['categories'][$code]['costsAmount'] += $costsAmt;
            $groups[$group]['categories'][$code]['stornoAmount'] += $stornoAmt;
            $groups[$group]['categories'][$code]['netAmount'] += $netAmt;
        }

        // Build widgetGroups list with rounding and sorting
        $widgetGroups = [];
        $totalCosts = 0.0;
        foreach ($groups as $group) {
            $categories = [];
            foreach ($group['categories'] as $cat) {
                $categories[] = [
                    'code'         => $cat['code'],
                    'name'         => $cat['name'],
                    'costsAmount'  => round($cat['costsAmount'], 2),
                    'stornoAmount' => round($cat['stornoAmount'], 2),
                    'netAmount'    => round($cat['netAmount'], 2),
                ];
            }

            usort($categories, static fn (array $a, array $b): int => $a['netAmount'] <=> $b['netAmount']);

            $netAmount = round($group['netAmount'], 2);
            $totalCosts += $netAmount;

            $widgetGroups[] = [
                'serviceGroup' => $group['serviceGroup'],
                'costsAmount'  => round($group['costsAmount'], 2),
                'stornoAmount' => round($group['stornoAmount'], 2),
                'netAmount'    => $netAmount,
                'categories'   => $categories,
            ];
        }

        usort($widgetGroups, static fn (array $a, array $b): int => $a['netAmount'] <=> $b['netAmount']);

        $totalCosts = round($totalCosts, 2);
        $revenue = round($revenue, 2);
        $returnsTotal = round($returnsTotal, 2);
        $costPriceTotal = round($costPriceTotal, 2);
        $profit = round($revenue + $returnsTotal + $costPriceTotal + $totalCosts, 2);

        $marginPercent = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;

        return [
            'revenue'        => $revenue,
            'returnsTotal'   => $returnsTotal,
            'costPriceTotal' => $costPriceTotal,
            'totalCosts'     => $totalCosts,
            'profit'         => $profit,
            'marginPercent'  => $marginPercent,
            'widgetGroups'   => $widgetGroups,
        ];
    }

    /**
     * Затраты по всем категориям за период — БЕЗ фильтра по listing_id.
     * Суммы в P&L-конвенции: расходы < 0, сторно > 0.
     *
     * В отличие от ListingCostAggregateQuery (per-listing) сюда попадают
     * категории затрат с listing_id = NULL (CPC, хранение, кросс-докинг и т.п.).
     *
     * Знак для ozon_compensation / ozon_decompensation определяется
     * category_code, а не operation_type: компенсация от Ozon — всегда доход,
     * декомпенсация — всегда расход. Это закрывает дыру в исторических данных,
     * где бэкфилл-миграция сохранила положительные компенсации как charge.
     *
     * @return list<array{
     *     category_code: string,
     *     category_name: string,
     *     net_amount: string,
     *     costs_amount: string,
     *     storno_amount: string,
     * }>
     */
    private function getCostAggregates(
        string $companyId,
        ?string $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $mpFilter = $marketplace !== null ? 'AND c.marketplace = :marketplace' : '';

        // Effective op type per строке считается в подзапросе — чтобы не
        // дублировать логику compensation/decompensation в трёх SUM(CASE ...).
        // Семантика: ozon_compensation всегда ведёт себя как storno (доход),
        // ozon_decompensation всегда как charge (расход). Остальные строки —
        // по фактическому operation_type.
        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                category_code,
                category_name,
                SUM(CASE WHEN effective_op = 'storno' THEN  ABS(amount) ELSE -ABS(amount) END) AS net_amount,
                SUM(CASE WHEN effective_op = 'storno' THEN 0            ELSE -ABS(amount) END) AS costs_amount,
                SUM(CASE WHEN effective_op = 'storno' THEN  ABS(amount) ELSE 0             END) AS storno_amount
            FROM (
                SELECT
                    cc.code  AS category_code,
                    cc.name  AS category_name,
                    c.amount AS amount,
                    CASE
                        WHEN cc.code = 'ozon_compensation'   THEN 'storno'
                        WHEN cc.code = 'ozon_decompensation' THEN 'charge'
                        ELSE c.operation_type
                    END AS effective_op
                FROM marketplace_costs c
                JOIN marketplace_cost_categories cc ON cc.id = c.category_id
                WHERE c.company_id = :companyId
                  AND c.cost_date >= :periodFrom
                  AND c.cost_date <= :periodTo
                  {$mpFilter}
            ) AS normalized
            GROUP BY category_code, category_name
            ORDER BY costs_amount ASC
            SQL,
            array_filter([
                'companyId'   => $companyId,
                'periodFrom'  => $from->format('Y-m-d'),
                'periodTo'    => $to->format('Y-m-d'),
                'marketplace' => $marketplace,
            ], static fn ($v) => $v !== null),
        );

        /** @var list<array{
         *     category_code: string,
         *     category_name: string,
         *     net_amount: string,
         *     costs_amount: string,
         *     storno_amount: string,
         * }> $rows */
        return $rows;
    }
}
