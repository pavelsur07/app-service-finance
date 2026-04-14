<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class UnitEconomyWidgetQuery
{
    private const array COST_TYPE_TO_WIDGET = [
        'commission'            => 'commission',
        'logistics_to'          => 'delivery',
        'logistics_back'        => 'delivery',
        'storage'               => 'delivery',
        'advertising_cpc'       => 'promo',
        'advertising_other'     => 'promo',
        'advertising_external'  => 'promo',
        'acquiring'             => 'partners',
        'penalties'             => 'other',
        'acceptance'            => 'other',
        'other'                 => 'other',
    ];

    private const array WIDGET_TO_COST_TYPES = [
        'commission' => ['commission'],
        'delivery'   => ['logistics_to', 'logistics_back', 'storage'],
        'partners'   => ['acquiring'],
        'promo'      => ['advertising_cpc', 'advertising_other', 'advertising_external'],
        'other'      => ['penalties', 'acceptance', 'other'],
    ];

    private const array WIDGET_TITLES = [
        'revenue'    => 'Доходы по категориям',
        'returns'    => 'Возвраты по категориям',
        'commission' => 'Вознаграждение по категориям',
        'delivery'   => 'Услуги доставки по категориям',
        'partners'   => 'Услуги партнёров по категориям',
        'promo'      => 'Продвижение и реклама по категориям',
        'other'      => 'Другие услуги и штрафы по категориям',
        'profit'     => 'Структура прибыли',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     revenue: string,
     *     returns: string,
     *     commission: string,
     *     delivery: string,
     *     partners: string,
     *     promo: string,
     *     other: string,
     *     profit: string,
     *     margin: float,
     * }
     */
    public function getWidgetsSummary(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ): array {
        $params = [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'dateFrom'    => $dateFrom->format('Y-m-d'),
            'dateTo'      => $dateTo->format('Y-m-d'),
        ];

        // 1) Выручка + себестоимость по продажам.
        $salesRow = $this->connection->fetchAssociative(
            <<<SQL
            SELECT
                COALESCE(SUM(s.total_revenue), 0)           AS revenue,
                COALESCE(SUM(s.quantity * s.cost_price), 0) AS cost_price_total
            FROM marketplace_sales s
            WHERE s.company_id = :companyId
              AND s.marketplace = :marketplace
              AND s.sale_date BETWEEN :dateFrom AND :dateTo
            SQL,
            $params,
        );

        $revenue        = $this->toMoney($salesRow['revenue'] ?? 0);
        $costPriceTotal = $this->toMoney($salesRow['cost_price_total'] ?? 0);

        // 2) Возвраты.
        $returnsRow = $this->connection->fetchAssociative(
            <<<SQL
            SELECT COALESCE(SUM(r.refund_amount), 0) AS returns
            FROM marketplace_returns r
            WHERE r.company_id = :companyId
              AND r.marketplace = :marketplace
              AND r.return_date BETWEEN :dateFrom AND :dateTo
            SQL,
            $params,
        );

        $returns = $this->toMoney($returnsRow['returns'] ?? 0);

        // 3) Затраты по типам юнит-экономики (все затраты компании, без фильтра listing_id).
        $costRows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                uecm.unit_economy_cost_type AS cost_type,
                SUM(CASE
                    WHEN c.operation_type = 'storno' THEN -ABS(c.amount)
                    ELSE ABS(c.amount)
                END) AS net
            FROM marketplace_costs c
            JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            JOIN unit_economy_cost_mappings uecm
                 ON uecm.cost_category_id = cc.id
                AND uecm.company_id = c.company_id
                AND uecm.marketplace = c.marketplace
            WHERE c.company_id = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date BETWEEN :dateFrom AND :dateTo
            GROUP BY uecm.unit_economy_cost_type
            SQL,
            $params,
        );

        $widgets = [
            'commission' => '0.00',
            'delivery'   => '0.00',
            'partners'   => '0.00',
            'promo'      => '0.00',
            'other'      => '0.00',
        ];

        foreach ($costRows as $row) {
            $costType  = (string) $row['cost_type'];
            $widgetKey = self::COST_TYPE_TO_WIDGET[$costType] ?? null;

            if ($widgetKey === null) {
                continue;
            }

            $widgets[$widgetKey] = bcadd(
                $widgets[$widgetKey],
                $this->toMoney($row['net'] ?? 0),
                2,
            );
        }

        $totalCosts = '0.00';
        foreach ($widgets as $amount) {
            $totalCosts = bcadd($totalCosts, $amount, 2);
        }

        $profit = bcsub(bcsub($revenue, $costPriceTotal, 2), $totalCosts, 2);

        $margin = 0.0;
        if (bccomp($revenue, '0.00', 2) === 1) {
            $margin = round(((float) $profit / (float) $revenue) * 100, 1);
        }

        return [
            'revenue'    => $revenue,
            'returns'    => $returns,
            'commission' => $widgets['commission'],
            'delivery'   => $widgets['delivery'],
            'partners'   => $widgets['partners'],
            'promo'      => $widgets['promo'],
            'other'      => $widgets['other'],
            'profit'     => $profit,
            'margin'     => $margin,
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     categories: list<array{name: string, charged: string, storno: string, isIncome?: bool}>,
     * }
     */
    public function getWidgetDetails(
        string $companyId,
        string $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $widgetKey,
    ): array {
        $title = self::WIDGET_TITLES[$widgetKey] ?? '';
        $from  = $dateFrom->format('Y-m-d');
        $to    = $dateTo->format('Y-m-d');

        if ($widgetKey === 'revenue') {
            $row = $this->connection->fetchAssociative(
                <<<SQL
                SELECT COALESCE(SUM(s.total_revenue), 0) AS charged
                FROM marketplace_sales s
                WHERE s.company_id = :companyId
                  AND s.marketplace = :marketplace
                  AND s.sale_date BETWEEN :dateFrom AND :dateTo
                SQL,
                [
                    'companyId'   => $companyId,
                    'marketplace' => $marketplace,
                    'dateFrom'    => $from,
                    'dateTo'      => $to,
                ],
            );

            return [
                'title'      => $title,
                'categories' => [
                    [
                        'name'    => 'Продажи',
                        'charged' => $this->toMoney($row['charged'] ?? 0),
                        'storno'  => '0.00',
                    ],
                ],
            ];
        }

        if ($widgetKey === 'returns') {
            $row = $this->connection->fetchAssociative(
                <<<SQL
                SELECT COALESCE(SUM(r.refund_amount), 0) AS charged
                FROM marketplace_returns r
                WHERE r.company_id = :companyId
                  AND r.marketplace = :marketplace
                  AND r.return_date BETWEEN :dateFrom AND :dateTo
                SQL,
                [
                    'companyId'   => $companyId,
                    'marketplace' => $marketplace,
                    'dateFrom'    => $from,
                    'dateTo'      => $to,
                ],
            );

            return [
                'title'      => $title,
                'categories' => [
                    [
                        'name'    => 'Возвраты',
                        'charged' => $this->toMoney($row['charged'] ?? 0),
                        'storno'  => '0.00',
                    ],
                ],
            ];
        }

        if ($widgetKey === 'profit') {
            $summary = $this->getWidgetsSummary($companyId, $marketplace, $dateFrom, $dateTo);

            // costPrice выводим из формулы profit = revenue - costPrice - sum(widgets):
            // costPrice = revenue - profit - sum(widgets).
            $widgetsSum = bcadd(
                bcadd(
                    bcadd($summary['commission'], $summary['delivery'], 2),
                    bcadd($summary['partners'], $summary['promo'], 2),
                    2,
                ),
                $summary['other'],
                2,
            );
            $costPrice = bcsub(bcsub($summary['revenue'], $summary['profit'], 2), $widgetsSum, 2);

            return [
                'title'      => $title,
                'categories' => [
                    ['name' => 'Выручка',        'charged' => $summary['revenue'],    'storno' => '0.00', 'isIncome' => true],
                    ['name' => 'Себестоимость',  'charged' => $costPrice,             'storno' => '0.00'],
                    ['name' => 'Вознаграждение', 'charged' => $summary['commission'], 'storno' => '0.00'],
                    ['name' => 'Услуги доставки','charged' => $summary['delivery'],   'storno' => '0.00'],
                    ['name' => 'Услуги партнёров','charged' => $summary['partners'],  'storno' => '0.00'],
                    ['name' => 'Продвижение',    'charged' => $summary['promo'],      'storno' => '0.00'],
                    ['name' => 'Другие услуги',  'charged' => $summary['other'],      'storno' => '0.00'],
                ],
            ];
        }

        $costTypes = self::WIDGET_TO_COST_TYPES[$widgetKey] ?? [];

        if ($costTypes === []) {
            return [
                'title'      => $title,
                'categories' => [],
            ];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT
                cc.name AS category_name,
                SUM(CASE
                    WHEN c.operation_type = 'storno' THEN 0
                    ELSE ABS(c.amount)
                END) AS charged,
                SUM(CASE
                    WHEN c.operation_type = 'storno' THEN ABS(c.amount)
                    ELSE 0
                END) AS storno
            FROM marketplace_costs c
            JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            JOIN unit_economy_cost_mappings uecm
                 ON uecm.cost_category_id = cc.id
                AND uecm.company_id = c.company_id
                AND uecm.marketplace = c.marketplace
            WHERE c.company_id = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date BETWEEN :dateFrom AND :dateTo
              AND uecm.unit_economy_cost_type IN (:costTypes)
            GROUP BY cc.name
            ORDER BY charged DESC
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'dateFrom'    => $from,
                'dateTo'      => $to,
                'costTypes'   => $costTypes,
            ],
            [
                'costTypes' => ArrayParameterType::STRING,
            ],
        );

        $categories = [];
        foreach ($rows as $row) {
            $categories[] = [
                'name'    => (string) $row['category_name'],
                'charged' => $this->toMoney($row['charged'] ?? 0),
                'storno'  => $this->toMoney($row['storno'] ?? 0),
            ];
        }

        return [
            'title'      => $title,
            'categories' => $categories,
        ];
    }

    /**
     * Нормализует произвольное числовое значение к строке с 2 знаками после запятой.
     */
    private function toMoney(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
