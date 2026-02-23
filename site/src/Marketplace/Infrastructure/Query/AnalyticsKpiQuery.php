<?php

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Query для получения KPI метрик аналитики маркетплейса
 *
 * Одним SQL запросом возвращает все 6 метрик + данные предыдущего периода для расчета роста
 */
class AnalyticsKpiQuery
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Получить все KPI метрики за период
     *
     * @return array{
     *   current: array{
     *     revenue: string,
     *     margin: string,
     *     units_sold: int,
     *     roi: float,
     *     return_rate: float,
     *     turnover_days: int,
     *     currency: string
     *   },
     *   previous: array{
     *     revenue: string,
     *     margin: string,
     *     units_sold: int
     *   }
     * }
     */
    public function getAllKpi(
        string $companyId,
        ?MarketplaceType $marketplace,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        // Вычисляем предыдущий период (той же длины)
        $periodDays = $from->diff($to)->days;
        $previousFrom = (clone $from)->modify("-{$periodDays} days");
        $previousTo = clone $from;

        $qb = $this->connection->createQueryBuilder();

        // Основной запрос с подзапросами для текущего и предыдущего периода
        $sql = "
            WITH current_period AS (
                SELECT
                    COALESCE(SUM(s.total_revenue), 0) as revenue,
                    COUNT(DISTINCT s.id) as units_sold,
                    -- Временная заглушка для остальных метрик (будет в следующих PR)
                    0 as margin,
                    0 as roi,
                    0 as return_rate,
                    0 as turnover_days
                FROM marketplace_sales s
                WHERE s.company_id = :company_id
                AND s.sale_date >= :from
                AND s.sale_date <= :to
                " . ($marketplace ? "AND s.marketplace = :marketplace" : "") . "
            ),
            previous_period AS (
                SELECT
                    COALESCE(SUM(s.total_revenue), 0) as revenue,
                    COUNT(DISTINCT s.id) as units_sold,
                    0 as margin
                FROM marketplace_sales s
                WHERE s.company_id = :company_id
                AND s.sale_date >= :previous_from
                AND s.sale_date < :previous_to
                " . ($marketplace ? "AND s.marketplace = :marketplace" : "") . "
            )
            SELECT
                -- Current period
                cp.revenue as current_revenue,
                cp.margin as current_margin,
                cp.units_sold as current_units_sold,
                cp.roi as current_roi,
                cp.return_rate as current_return_rate,
                cp.turnover_days as current_turnover_days,
                'RUB' as currency,  -- ← КОНСТАНТА

                -- Previous period (для расчета роста)
                pp.revenue as previous_revenue,
                pp.margin as previous_margin,
                pp.units_sold as previous_units_sold
            FROM current_period cp
            CROSS JOIN previous_period pp
        ";

        $params = [
            'company_id' => $companyId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'previous_from' => $previousFrom->format('Y-m-d'),
            'previous_to' => $previousTo->format('Y-m-d'),
        ];

        if ($marketplace) {
            $params['marketplace'] = $marketplace->value;
        }

        $result = $this->connection->executeQuery($sql, $params)->fetchAssociative();

        if (!$result) {
            // Пустой результат
            return [
                'current' => [
                    'revenue' => '0',
                    'margin' => '0',
                    'units_sold' => 0,
                    'roi' => 0.0,
                    'return_rate' => 0.0,
                    'turnover_days' => 0,
                    'currency' => 'RUB',
                ],
                'previous' => [
                    'revenue' => '0',
                    'margin' => '0',
                    'units_sold' => 0,
                ],
            ];
        }

        return [
            'current' => [
                'revenue' => (string)$result['current_revenue'],
                'margin' => (string)$result['current_margin'],
                'units_sold' => (int)$result['current_units_sold'],
                'roi' => (float)$result['current_roi'],
                'return_rate' => (float)$result['current_return_rate'],
                'turnover_days' => (int)$result['current_turnover_days'],
                'currency' => (string)$result['currency'],
            ],
            'previous' => [
                'revenue' => (string)$result['previous_revenue'],
                'margin' => (string)$result['previous_margin'],
                'units_sold' => (int)$result['previous_units_sold'],
            ],
        ];
    }
}
