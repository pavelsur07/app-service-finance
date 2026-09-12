<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Application\Service\MarketplaceCostAnalyticsGroupResolver;
use Doctrine\DBAL\ArrayParameterType;
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
 *
 * Исключение — фильтр по тегам: тег живёт на листинге, поэтому под фильтром
 * считаются только затраты отфильтрованных листингов, а строки с
 * listing_id IS NULL выпадают — распределить их на конкретный тег не на чем.
 */
final readonly class WidgetSummaryQuery
{
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
        private MarketplaceCostAnalyticsGroupResolver $groupResolver,
    ) {
    }

    /**
     * @param list<string>|null $listingIds ограничение выборки листингами; null = без
     *                                      ограничения. Теги в листинги резолвит
     *                                      вызывающий — запрос остаётся агрегацией
     *                                      над явно заданным набором и не ходит
     *                                      в чужой модуль посреди подсчёта.
     *
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
        ?array $listingIds = null,
    ): array {
        $sales = $this->marketplaceFacade->getSalesAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);
        $returns = $this->marketplaceFacade->getReturnAggregatesByListing($companyId, $marketplace, $dateFrom, $dateTo);

        // Под фильтром по тегам виджеты обязаны считать то же, что строка «Итого»
        // таблицы на этой же странице. Иначе карточка сверху утверждает одно,
        // а итог под ней — другое, и обе цифры перестают что-либо значить.
        if (null !== $listingIds) {
            $allowedKeys = array_flip($listingIds);
            $sales = array_intersect_key($sales, $allowedKeys);
            $returns = array_intersect_key($returns, $allowedKeys);
        }

        $costRows = $this->getCostAggregates($companyId, $marketplace, $dateFrom, $dateTo, $listingIds);

        $revenue = 0.0;
        $returnsTotal = 0.0;
        $costPriceTotal = 0.0;

        $groups = [];
        foreach (self::WIDGET_GROUPS as $groupName) {
            $groups[$groupName] = [
                'serviceGroup' => $groupName,
                'costsAmount' => 0.0,
                'stornoAmount' => 0.0,
                'netAmount' => 0.0,
                'categories' => [],
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

            $marketplaceFromRow = (string) $row['marketplace'];
            $group = $this->groupResolver->resolveWidgetGroup($marketplaceFromRow, $code, $name);

            $groups[$group]['costsAmount'] += $costsAmt;
            $groups[$group]['stornoAmount'] += $stornoAmt;
            $groups[$group]['netAmount'] += $netAmt;

            // Агрегируем одинаковые categoryCode внутри группы
            // (на всякий случай — SQL уже группирует по cc.code, cc.name)
            $categoryKey = $marketplaceFromRow.':'.$code;

            if (!isset($groups[$group]['categories'][$categoryKey])) {
                $groups[$group]['categories'][$categoryKey] = [
                    'code' => $code,
                    'name' => $name,
                    'costsAmount' => 0.0,
                    'stornoAmount' => 0.0,
                    'netAmount' => 0.0,
                ];
            }

            $groups[$group]['categories'][$categoryKey]['costsAmount'] += $costsAmt;
            $groups[$group]['categories'][$categoryKey]['stornoAmount'] += $stornoAmt;
            $groups[$group]['categories'][$categoryKey]['netAmount'] += $netAmt;
        }

        // Build widgetGroups list with rounding and sorting
        $widgetGroups = [];
        $totalCosts = 0.0;
        foreach ($groups as $group) {
            $categories = [];
            foreach ($group['categories'] as $cat) {
                $categories[] = [
                    'code' => $cat['code'],
                    'name' => $cat['name'],
                    'costsAmount' => round($cat['costsAmount'], 2),
                    'stornoAmount' => round($cat['stornoAmount'], 2),
                    'netAmount' => round($cat['netAmount'], 2),
                ];
            }

            usort($categories, static fn (array $a, array $b): int => $a['netAmount'] <=> $b['netAmount']);

            $netAmount = round($group['netAmount'], 2);
            $totalCosts += $netAmount;

            $widgetGroups[] = [
                'serviceGroup' => $group['serviceGroup'],
                'costsAmount' => round($group['costsAmount'], 2),
                'stornoAmount' => round($group['stornoAmount'], 2),
                'netAmount' => $netAmount,
                'categories' => $categories,
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
            'revenue' => $revenue,
            'returnsTotal' => $returnsTotal,
            'costPriceTotal' => $costPriceTotal,
            'totalCosts' => $totalCosts,
            'profit' => $profit,
            'marginPercent' => $marginPercent,
            'widgetGroups' => $widgetGroups,
        ];
    }

    /**
     * Затраты по всем категориям за период — БЕЗ фильтра по listing_id.
     * Суммы в P&L-конвенции: расходы < 0, сторно > 0.
     *
     * В отличие от ListingCostAggregateQuery (per-listing) сюда попадают
     * категории затрат с listing_id = NULL (CPC, хранение, кросс-докинг и т.п.).
     *
     * Знак берётся из operation_type — как у всех остальных строк.
     *
     * Раньше здесь стоял обход: ozon_compensation принудительно считался
     * доходом, ozon_decompensation расходом, независимо от operation_type. Он
     * закрывал дыру в исторических данных, где бэкфилл сохранил положительные
     * компенсации как charge. Дыры больше нет: на 11.09.2026 все 66 компенсаций
     * имеют storno, все 51 декомпенсация — charge, и ни одной строки, где обход
     * менял бы исход. При этом он маскировал бы настоящее направление у новых
     * данных: разбор by-day пишет знак по каждой записи.
     *
     * @param list<string>|null $listingIds null = без ограничения по листингам (прежнее
     *                                      поведение). Непустой список приходит только
     *                                      из фильтра по тегам и отсекает в том числе
     *                                      строки с listing_id IS NULL: CPC, хранение и
     *                                      прочие затраты, которые к конкретному тегу
     *                                      не относятся и распределить их не на чем.
     *                                      То же решение принято для totals.adSpend
     *                                      в UnitExtendedQuery.
     *
     * @return list<array{
     *     marketplace: string,
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
        ?array $listingIds = null,
    ): array {
        // Скоуп пуст (тег не выбрал ни одного листинга) — атрибутируемых затрат нет.
        // Возврат пустого списка здесь, а не `IN ()` в SQL, который Postgres не примет.
        if ([] === $listingIds) {
            return [];
        }

        $mpFilter = null !== $marketplace ? 'AND c.marketplace = :marketplace' : '';
        $listingFilter = null !== $listingIds ? 'AND c.listing_id IN (:listingIds)' : '';

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                marketplace,
                category_code,
                category_name,
                SUM(CASE WHEN effective_op = 'storno' THEN  ABS(amount) ELSE -ABS(amount) END) AS net_amount,
                SUM(CASE WHEN effective_op = 'storno' THEN 0            ELSE -ABS(amount) END) AS costs_amount,
                SUM(CASE WHEN effective_op = 'storno' THEN  ABS(amount) ELSE 0             END) AS storno_amount
            FROM (
                SELECT
                    c.marketplace AS marketplace,
                    cc.code  AS category_code,
                    cc.name  AS category_name,
                    c.amount AS amount,
                    c.operation_type AS effective_op
                FROM marketplace_costs c
                JOIN marketplace_cost_categories cc ON cc.id = c.category_id
                WHERE c.company_id = :companyId
                  AND c.cost_date >= :periodFrom
                  AND c.cost_date <= :periodTo
                  {$mpFilter}
                  {$listingFilter}
            ) AS normalized
            GROUP BY marketplace, category_code, category_name
            ORDER BY costs_amount ASC
            SQL,
            array_filter([
                'companyId' => $companyId,
                'periodFrom' => $from->format('Y-m-d'),
                'periodTo' => $to->format('Y-m-d'),
                'marketplace' => $marketplace,
                'listingIds' => $listingIds,
            ], static fn ($v) => null !== $v),
            null !== $listingIds ? ['listingIds' => ArrayParameterType::STRING] : [],
        );

        /* @var list<array{
         *     marketplace: string,
         *     category_code: string,
         *     category_name: string,
         *     net_amount: string,
         *     costs_amount: string,
         *     storno_amount: string,
         * }> $rows */
        return $rows;
    }
}
