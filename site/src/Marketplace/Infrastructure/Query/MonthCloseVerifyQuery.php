<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Компактная сверка данных закрытия месяца для ручной проверки.
 *
 * Возвращает JSON пригодный для копирования в чат/Postman и сравнения
 * с отчётами из личного кабинета маркетплейса.
 *
 * Проверяет:
 *   1. realization_coverage    — заполнены ли поля return_commission после переобработки
 *   2. realization_totals      — итоги выручки и возвратов по реализации Ozon
 *   3. sales_returns_totals    — итоги продаж и возвратов из marketplace_sales/returns
 *   4. mapping_coverage        — настроены ли маппинги для всех AmountSource
 *   5. month_close_status      — статус этапов закрытия месяца
 *   6. pl_documents            — созданные PLDocument за период
 */
final class MonthCloseVerifyQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return [
            'realization_coverage'   => $this->checkRealizationCoverage($companyId, $periodFrom, $periodTo),
            'realization_totals'     => $this->realizationTotals($companyId, $periodFrom, $periodTo),
            'sales_returns_totals'   => $this->salesReturnsTotals($companyId, $marketplace, $periodFrom, $periodTo),
            'mapping_coverage'       => $this->mappingCoverage($companyId, $marketplace),
            'month_close_status'     => $this->monthCloseStatus($companyId, $marketplace, $periodFrom, $periodTo),
            'pl_documents'           => $this->plDocuments($companyId, $marketplace, $periodFrom, $periodTo),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Проверка: все ли строки реализации переобработаны (return_commission заполнен где нужно).
     * Помогает убедиться что кнопка «Применить выручку» была нажата.
     */
    private function checkRealizationCoverage(
        string $companyId,
        string $periodFrom,
        string $periodTo,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                                        AS total_rows,
                COUNT(return_amount)                           AS rows_with_return,
                COUNT(*) FILTER (WHERE return_amount IS NULL)  AS rows_without_return,
                COUNT(*) FILTER (WHERE pl_document_id IS NOT NULL) AS rows_closed,
                COUNT(*) FILTER (WHERE pl_document_id IS NULL)     AS rows_open
            FROM marketplace_ozon_realizations
            WHERE company_id = :companyId
              AND period_from >= :periodFrom
              AND period_to   <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $periodFrom, 'periodTo' => $periodTo],
        );

        $total = (int) ($row['total_rows'] ?? 0);

        return [
            'total_rows'         => $total,
            'rows_with_return'   => (int) ($row['rows_with_return'] ?? 0),
            'rows_without_return'=> (int) ($row['rows_without_return'] ?? 0),
            'rows_closed'        => (int) ($row['rows_closed'] ?? 0),
            'rows_open'          => (int) ($row['rows_open'] ?? 0),
            'status'             => $total === 0
                ? 'NO_DATA — нет строк реализации за период. Нажмите «Применить выручку».'
                : (((int) ($row['rows_with_return'] ?? 0)) > 0
                    ? 'OK — return_commission заполнен в строках где есть возврат'
                    : 'WARNING — возможно данные ещё не переобработаны (return_amount = NULL везде)'),
        ];
    }

    /**
     * Итоги реализации Ozon — для сверки с xlsx-отчётом из ЛК.
     * Сравни total_sale_amount с «Реализовано на сумму, руб.»
     * и total_return_amount с «Возвращено на сумму, руб.».
     */
    private function realizationTotals(
        string $companyId,
        string $periodFrom,
        string $periodTo,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                            AS total_rows,
                SUM(total_amount)                   AS total_sale_amount,
                COUNT(return_amount)                AS return_rows,
                COALESCE(SUM(return_amount), 0)     AS total_return_amount,
                SUM(seller_price_per_instance * quantity) AS total_seller_price_amount
            FROM marketplace_ozon_realizations
            WHERE company_id = :companyId
              AND period_from >= :periodFrom
              AND period_to   <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $periodFrom, 'periodTo' => $periodTo],
        );

        // По маппингу — сколько войдёт в ОПиУ
        $mappedSale = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COALESCE(SUM(r.total_amount), 0)
            FROM marketplace_ozon_realizations r
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
               AND m.marketplace = 'ozon'
               AND m.amount_source = 'sale_realization'
               AND m.is_active = true
            WHERE r.company_id = :companyId
              AND r.period_from >= :periodFrom
              AND r.period_to   <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $periodFrom, 'periodTo' => $periodTo],
        );

        $mappedReturn = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COALESCE(SUM(r.return_amount), 0)
            FROM marketplace_ozon_realizations r
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
               AND m.marketplace = 'ozon'
               AND m.amount_source = 'return_realization'
               AND m.is_active = true
            WHERE r.company_id = :companyId
              AND r.return_amount IS NOT NULL
              AND r.period_from >= :periodFrom
              AND r.period_to   <= :periodTo
            SQL,
            ['companyId' => $companyId, 'periodFrom' => $periodFrom, 'periodTo' => $periodTo],
        );

        return [
            'hint'                     => 'Сравни total_sale_amount с «Реализовано на сумму» и total_return_amount с «Возвращено на сумму» из xlsx-отчёта Ozon',
            'total_rows'               => (int)   ($row['total_rows'] ?? 0),
            'total_sale_amount'        => number_format((float) ($row['total_sale_amount'] ?? 0), 2, '.', ' '),
            'total_seller_price_amount'=> number_format((float) ($row['total_seller_price_amount'] ?? 0), 2, '.', ' '),
            'return_rows'              => (int)   ($row['return_rows'] ?? 0),
            'total_return_amount'      => number_format((float) ($row['total_return_amount'] ?? 0), 2, '.', ' '),
            'mapped_to_pl' => [
                'sale_realization'   => number_format((float) $mappedSale, 2, '.', ' '),
                'return_realization' => number_format((float) $mappedReturn, 2, '.', ' '),
                'status_sale'        => $mappedSale > 0 ? 'OK' : 'WARNING — маппинг sale_realization не настроен или нет данных',
                'status_return'      => $mappedReturn > 0 ? 'OK' : 'WARNING — маппинг return_realization не настроен или нет данных',
            ],
        ];
    }

    /**
     * Итоги продаж и возвратов из marketplace_sales / marketplace_returns.
     */
    private function salesReturnsTotals(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $sales = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                                           AS total,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL)   AS closed,
                COUNT(*) FILTER (WHERE document_id IS NULL)        AS open,
                COALESCE(SUM(total_revenue), 0)                    AS total_revenue,
                COUNT(*) FILTER (WHERE cost_price IS NULL OR cost_price = '0.00') AS without_cost
            FROM marketplace_sales
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND sale_date   >= :periodFrom
              AND sale_date   <= :periodTo
            SQL,
            ['companyId' => $companyId, 'marketplace' => $marketplace, 'periodFrom' => $periodFrom, 'periodTo' => $periodTo],
        );

        $returns = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                                           AS total,
                COUNT(*) FILTER (WHERE document_id IS NOT NULL)   AS closed,
                COUNT(*) FILTER (WHERE document_id IS NULL)        AS open,
                COALESCE(SUM(refund_amount), 0)                    AS total_refund
            FROM marketplace_returns
            WHERE company_id   = :companyId
              AND marketplace  = :marketplace
              AND return_date >= :periodFrom
              AND return_date <= :periodTo
            SQL,
            ['companyId' => $companyId, 'marketplace' => $marketplace, 'periodFrom' => $periodFrom, 'periodTo' => $periodTo],
        );

        return [
            'sales' => [
                'total'        => (int)   ($sales['total'] ?? 0),
                'closed'       => (int)   ($sales['closed'] ?? 0),
                'open'         => (int)   ($sales['open'] ?? 0),
                'total_revenue'=> number_format((float) ($sales['total_revenue'] ?? 0), 2, '.', ' '),
                'without_cost' => (int)   ($sales['without_cost'] ?? 0),
                'cost_status'  => ((int) ($sales['without_cost'] ?? 0)) === 0
                    ? 'OK — у всех продаж есть себестоимость'
                    : 'WARNING — есть продажи без себестоимости, запусти пересчёт',
            ],
            'returns' => [
                'total'        => (int)   ($returns['total'] ?? 0),
                'closed'       => (int)   ($returns['closed'] ?? 0),
                'open'         => (int)   ($returns['open'] ?? 0),
                'total_refund' => number_format((float) ($returns['total_refund'] ?? 0), 2, '.', ' '),
            ],
        ];
    }

    /**
     * Проверка маппингов — все ли нужные AmountSource настроены.
     */
    private function mappingCoverage(string $companyId, string $marketplace): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT amount_source, operation_type, is_active,
                   pc.name AS pl_category_name
            FROM marketplace_sale_mappings m
            LEFT JOIN pl_categories pc ON pc.id = m.pl_category_id
            WHERE m.company_id   = :companyId
              AND m.marketplace  = :marketplace
            ORDER BY operation_type, amount_source
            SQL,
            ['companyId' => $companyId, 'marketplace' => $marketplace],
        );

        $configured = array_column($rows, null, 'amount_source');

        $required = $marketplace === 'ozon'
            ? ['sale_realization', 'return_realization', 'sale_cost_price', 'return_cost_price']
            : ['sale_gross', 'sale_cost_price', 'return_gross', 'return_cost_price'];

        $missing = [];
        foreach ($required as $source) {
            if (!isset($configured[$source])) {
                $missing[] = $source;
            }
        }

        return [
            'configured' => array_map(static fn (array $r) => [
                'amount_source'    => $r['amount_source'],
                'operation_type'   => $r['operation_type'],
                'is_active'        => (bool) $r['is_active'],
                'pl_category_name' => $r['pl_category_name'],
            ], $rows),
            'missing_recommended' => $missing,
            'status' => empty($missing)
                ? 'OK — все рекомендуемые маппинги настроены'
                : 'WARNING — не хватает маппингов: ' . implode(', ', $missing),
        ];
    }

    /**
     * Статус этапов закрытия месяца.
     */
    private function monthCloseStatus(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                stage_sales_returns_status,
                stage_sales_returns_closed_at,
                stage_sales_returns_pl_document_ids,
                stage_costs_status,
                stage_costs_closed_at,
                stage_costs_pl_document_ids
            FROM marketplace_month_closes
            WHERE company_id  = :companyId
              AND marketplace = :marketplace
              AND period_from = :periodFrom
            SQL,
            ['companyId' => $companyId, 'marketplace' => $marketplace, 'periodFrom' => $periodFrom],
        );

        if (!$row) {
            return ['status' => 'NOT_FOUND — закрытие месяца ещё не создавалось'];
        }

        return [
            'stage_sales_returns' => [
                'status'      => $row['stage_sales_returns_status'],
                'closed_at'   => $row['stage_sales_returns_closed_at'],
                'document_ids'=> json_decode((string) ($row['stage_sales_returns_pl_document_ids'] ?? 'null'), true) ?? [],
            ],
            'stage_costs' => [
                'status'      => $row['stage_costs_status'],
                'closed_at'   => $row['stage_costs_closed_at'],
                'document_ids'=> json_decode((string) ($row['stage_costs_pl_document_ids'] ?? 'null'), true) ?? [],
            ],
        ];
    }

    /**
     * Созданные PLDocument за период с суммами строк.
     */
    private function plDocuments(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                d.id,
                d.description,
                d.date::text        AS date,
                d.source,
                d.stream,
                COUNT(o.id)         AS operations_count,
                SUM(o.amount)       AS total_amount
            FROM documents d
            LEFT JOIN document_operations o ON o.document_id = d.id
            WHERE d.company_id = :companyId
              AND d.date >= :periodFrom
              AND d.date <= :periodTo
              AND d.source IN (:sources)
            GROUP BY d.id, d.description, d.date, d.source, d.stream
            ORDER BY d.date DESC
            SQL,
            [
                'companyId'  => $companyId,
                'periodFrom' => $periodFrom,
                'periodTo'   => $periodTo,
                'sources'    => $this->resolveDocumentSources($marketplace),
            ],
            [
                'sources' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        );

        return array_map(static fn (array $r) => [
            'id'               => $r['id'],
            'date'             => $r['date'],
            'description'      => $r['description'],
            'source'           => $r['source'],
            'stream'           => $r['stream'],
            'operations_count' => (int) $r['operations_count'],
            'total_amount'     => number_format((float) $r['total_amount'], 2, '.', ' '),
        ], $rows);
    }

    /** @return string[] */
    private function resolveDocumentSources(string $marketplace): array
    {
        return match ($marketplace) {
            'ozon'          => ['marketplace_ozon'],
            'wildberries'   => ['marketplace_wb'],
            'yandex_market' => ['marketplace_yandex'],
            default         => ['marketplace_ozon', 'marketplace_wb', 'marketplace_yandex', 'marketplace_sber'],
        };
    }
}
