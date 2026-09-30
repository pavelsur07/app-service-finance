<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query для гейта нераспознанных затрат WB: читает готовые счётчики
 * `unprocessed_cost_types` сырых документов, а не `raw_data`.
 *
 * Запрос сквозной по компаниям намеренно — это системный гейт по всем активным
 * seller-подключениям, как ActiveWbConnectionsQuery. Охват проверки равен охвату
 * починки: компания без активного подключения в выборку не попадает.
 */
final class WbUnrecognizedCostsQuery
{
    private const string ACTIVE_CONNECTION = <<<'SQL'
        EXISTS (
            SELECT 1 FROM marketplace_connections mc
            WHERE mc.company_id = d.company_id
              AND mc.marketplace = 'wildberries'
              AND mc.connection_type = 'seller'
              AND mc.is_active = true
        )
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Нераспознанные операции за окно, по компании и названию операции.
     *
     * @return list<array{company_id: string, operation: string, rows_count: int, documents: int, first_day: string, last_day: string}>
     */
    public function findUnrecognized(\DateTimeImmutable $fromDay): array
    {
        $sql = <<<'SQL'
            SELECT d.company_id,
                   t.key AS operation,
                   SUM(t.value::int) AS rows_count,
                   COUNT(*) AS documents,
                   MIN(d.period_from) AS first_day,
                   MAX(d.period_from) AS last_day
            FROM marketplace_raw_documents d
            CROSS JOIN LATERAL jsonb_each_text(
                CASE WHEN jsonb_typeof(d.unprocessed_cost_types::jsonb) = 'object'
                     THEN d.unprocessed_cost_types::jsonb
                     ELSE '{}'::jsonb END
            ) t
            WHERE d.marketplace = 'wildberries'
              AND d.document_type = 'sales_report'
              AND d.period_from >= :from_day
              AND d.unprocessed_costs_count > 0
              AND %s
            GROUP BY d.company_id, t.key
            ORDER BY d.company_id, rows_count DESC, t.key
            SQL;

        /** @var list<array{company_id: string, operation: string, rows_count: int|string, documents: int|string, first_day: string, last_day: string}> $rows */
        $rows = $this->connection->executeQuery(
            sprintf($sql, self::ACTIVE_CONNECTION),
            ['from_day' => $fromDay->format('Y-m-d')],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'company_id' => (string) $row['company_id'],
            'operation' => (string) $row['operation'],
            'rows_count' => (int) $row['rows_count'],
            'documents' => (int) $row['documents'],
            'first_day' => (string) $row['first_day'],
            'last_day' => (string) $row['last_day'],
        ], $rows);
    }

    /**
     * Охват проверки: сколько документов и компаний просмотрено и у скольких не
     * отработал шаг `costs` — у них нулевой счётчик ничего не доказывает.
     *
     * @return array{documents: int, companies: int, costs_not_processed: int}
     */
    public function coverage(\DateTimeImmutable $fromDay): array
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) AS documents,
                   COUNT(DISTINCT d.company_id) AS companies,
                   COUNT(*) FILTER (
                       WHERE NOT (COALESCE(d.succeeded_steps::jsonb, '[]'::jsonb) @> '["costs"]'::jsonb)
                   ) AS costs_not_processed
            FROM marketplace_raw_documents d
            WHERE d.marketplace = 'wildberries'
              AND d.document_type = 'sales_report'
              AND d.period_from >= :from_day
              AND %s
            SQL;

        /** @var array{documents: int|string, companies: int|string, costs_not_processed: int|string}|false $row */
        $row = $this->connection->executeQuery(
            sprintf($sql, self::ACTIVE_CONNECTION),
            ['from_day' => $fromDay->format('Y-m-d')],
        )->fetchAssociative();

        return [
            'documents' => (int) ($row['documents'] ?? 0),
            'companies' => (int) ($row['companies'] ?? 0),
            'costs_not_processed' => (int) ($row['costs_not_processed'] ?? 0),
        ];
    }
}
