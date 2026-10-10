<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query для гейта «затраты вне ОПиУ»: затраты без документа ОПиУ, у
 * категории которых нет правила маппинга или правило без статьи.
 *
 * Условие — PreflightCostsQuery::WITHOUT_PL_DECISION, по которому preflight
 * блокирует окончательное закрытие: правило с `include_in_pl = false` —
 * осознанное исключение, не пропуск. Для Ozon тот же PreliminaryCostFilter, что
 * у оперативного закрытия: нераспознанные коды маппинг не чинит, их ловит сверка Ozon.
 *
 * Запрос сквозной по компаниям намеренно — это системный гейт по активным
 * SELLER-подключениям, как ActiveSellerConnectionsQuery.
 */
final readonly class UnmappedCostsQuery
{
    private const string ACTIVE_CONNECTION = <<<'SQL'
        EXISTS (
            SELECT 1 FROM marketplace_connections conn
            WHERE conn.company_id = c.company_id
              AND conn.marketplace = c.marketplace
              AND conn.connection_type = 'seller'
              AND conn.is_active = true
        )
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{company_id: string, code: string, rows_count: int, amount: string, first_day: string, last_day: string}>
     */
    public function findUnmapped(string $marketplace, \DateTimeImmutable $fromDay): array
    {
        [$filter, $filterParams, $filterTypes] = PreliminaryCostFilter::build($marketplace, true);

        $sql = sprintf(<<<'SQL'
            SELECT c.company_id,
                   mcc.code,
                   COUNT(*) AS rows_count,
                   ROUND(SUM(c.amount), 2) AS amount,
                   MIN(c.cost_date) AS first_day,
                   MAX(c.cost_date) AS last_day
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories mcc ON mcc.id = c.category_id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
               AND m.company_id = c.company_id
            WHERE c.marketplace = :marketplace
              AND c.cost_date >= :fromDay
              AND c.document_id IS NULL
              AND %s
              AND %s
              %s
            GROUP BY c.company_id, mcc.code
            HAVING ABS(SUM(c.amount)) > 0.001
            ORDER BY c.company_id, mcc.code
            SQL, PreflightCostsQuery::WITHOUT_PL_DECISION, self::ACTIVE_CONNECTION, $filter);

        $rows = $this->connection->fetchAllAssociative($sql, [
            'marketplace' => $marketplace,
            'fromDay' => $fromDay->format('Y-m-d'),
            ...$filterParams,
        ], $filterTypes);

        return array_map(static fn (array $r): array => [
            'company_id' => (string) $r['company_id'],
            'code' => (string) $r['code'],
            'rows_count' => (int) $r['rows_count'],
            'amount' => (string) $r['amount'],
            'first_day' => (string) $r['first_day'],
            'last_day' => (string) $r['last_day'],
        ], $rows);
    }
}
