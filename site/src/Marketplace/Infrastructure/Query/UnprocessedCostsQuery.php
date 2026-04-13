<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Агрегирует необработанные расходы маркетплейса для создания строк PLDocument.
 *
 * ВАЖНО: каждая категория затрат = отдельная строка документа ОПиУ.
 * Сторно выделяется в отдельную строку с обратным знаком.
 *
 * Пример результата для одной категории с частичным сторно:
 *   { category: ozon_logistic_direct, is_storno: false, amount: 569700.81, description: "Логистика к покупателю Ozon" }
 *   { category: ozon_logistic_direct, is_storno: true,  amount: 317.66,    description: "Логистика к покупателю Ozon [сторно]" }
 *
 * Знаковая логика:
 *   is_storno = false → затрата → is_negative = маппинг.is_negative (обычно true)
 *   is_storno = true  → возврат затраты → is_negative = !маппинг.is_negative
 *
 * WHERE document_id IS NULL — только необработанные записи.
 *
 * Маппинг: MarketplaceCost → marketplace_cost_pl_mappings → pl_category_id.
 * Условия включения в ОПиУ:
 *   - маппинг существует
 *   - include_in_pl = true
 *   - pl_category_id задан
 */
final class UnprocessedCostsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{
     *     cost_category_code: string,
     *     cost_category_name: string,
     *     pl_category_id: string,
     *     costs_amount: string,
     *     storno_amount: string,
     *     net_amount: string,
     *     is_storno: bool,
     *     total_amount: string,
     *     description: string,
     *     is_negative: bool,
     *     sort_order: int,
     *     records_count: int
     * }>
     */
    public function execute(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        // Получаем все строки с разбивкой по категории И знаку (costs vs storno).
        //
        // is_storno / costs_amount / storno_amount классифицируются по operation_type
        // с fallback на знак amount:
        //   - operation_type IS NOT NULL (новая схема, Ozon post-backfill) → operation_type = 'storno'
        //   - operation_type IS NULL (legacy: WB-строки, unmigrated pre-Phase-2A) → amount < 0
        //
        // Fallback нужен на период перехода: WB ещё эмитирует NULL operation_type,
        // Ozon до бэкфилла тоже NULL. После полной миграции (Phase 2B) fallback снимается.
        //
        // Важно: все три поля (is_storno / costs_amount / storno_amount) используют одну
        // и ту же классификацию, иначе для post-migration Ozon (amount > 0, operation_type='storno')
        // строки помеченные is_storno=true попадут в costs_amount вместо storno_amount.
        $sql = <<<'SQL'
            SELECT
                mcc.code                                                        AS cost_category_code,
                mcc.name                                                        AS cost_category_name,
                m.pl_category_id                                                AS pl_category_id,
                (CASE
                    WHEN c.operation_type IS NOT NULL THEN (c.operation_type = 'storno')
                    ELSE (c.amount < 0)
                END)                                                            AS is_storno,
                SUM(CASE
                    WHEN (CASE
                            WHEN c.operation_type IS NOT NULL THEN (c.operation_type = 'storno')
                            ELSE (c.amount < 0)
                         END)
                    THEN 0
                    ELSE ABS(c.amount)
                END)                                                            AS costs_amount,
                SUM(CASE
                    WHEN (CASE
                            WHEN c.operation_type IS NOT NULL THEN (c.operation_type = 'storno')
                            ELSE (c.amount < 0)
                         END)
                    THEN ABS(c.amount)
                    ELSE 0
                END)                                                            AS storno_amount,
                ABS(SUM(c.amount))                                              AS total_amount,
                m.is_negative                                                   AS mapping_is_negative,
                m.sort_order                                                    AS sort_order,
                COUNT(c.id)                                                     AS records_count
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories mcc
                ON mcc.id = c.category_id
            INNER JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
                AND c.marketplace = :marketplace
                AND c.document_id IS NULL
                AND c.cost_date  >= :periodFrom
                AND c.cost_date  <= :periodTo
                AND m.include_in_pl = true
                AND m.pl_category_id IS NOT NULL
            GROUP BY
                mcc.code,
                mcc.name,
                m.pl_category_id,
                (CASE
                    WHEN c.operation_type IS NOT NULL THEN (c.operation_type = 'storno')
                    ELSE (c.amount < 0)
                END),
                m.is_negative,
                m.sort_order
            HAVING ABS(SUM(c.amount)) > 0.001
            ORDER BY m.sort_order ASC, mcc.name ASC,
                (CASE
                    WHEN c.operation_type IS NOT NULL THEN (c.operation_type = 'storno')
                    ELSE (c.amount < 0)
                END) ASC
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ]);

        return array_map(static function (array $r): array {
            $isStorno       = (bool) $r['is_storno'];
            $mappingNegative = (bool) $r['mapping_is_negative'];

            // Сторно инвертирует знак маппинга:
            // затрата (is_storno=false): is_negative = маппинг (обычно true = расход в ОПиУ)
            // сторно  (is_storno=true):  is_negative = !маппинг (уменьшение расхода)
            $isNegative = $isStorno ? !$mappingNegative : $mappingNegative;

            $description = $r['cost_category_name'];
            if ($isStorno) {
                $description .= ' [сторно]';
            }

            return [
                'cost_category_code' => $r['cost_category_code'],
                'cost_category_name' => $r['cost_category_name'],
                'pl_category_id'     => $r['pl_category_id'],
                'costs_amount'       => $r['costs_amount'],
                'storno_amount'      => $r['storno_amount'],
                'net_amount'         => (string) ((float) $r['costs_amount'] - (float) $r['storno_amount']),
                'is_storno'          => $isStorno,
                'total_amount'       => $r['total_amount'],
                'description'        => $description,
                'is_negative'        => $isNegative,
                'sort_order'         => (int) $r['sort_order'],
                'records_count'      => (int) $r['records_count'],
            ];
        }, $rows);
    }

    /**
     * Контрольная сумма для сверки после создания PLDocument.
     *
     * Возвращает net_amount всех затрат которые войдут в документ.
     * После создания PLDocument — сумма строк документа должна совпадать.
     */
    public function getControlSum(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): string {
        $result = $this->connection->fetchOne(
            <<<'SQL'
            SELECT COALESCE(SUM(c.amount), 0)
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
                AND c.marketplace = :marketplace
                AND c.document_id IS NULL
                AND c.cost_date  >= :periodFrom
                AND c.cost_date  <= :periodTo
                AND m.include_in_pl = true
                AND m.pl_category_id IS NOT NULL
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );

        return (string) $result;
    }
}
