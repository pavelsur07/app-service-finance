<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для preflight проверок этапа COSTS.
 *
 * Проверяет:
 *   - количество затрат за период
 *   - количество затрат без решения по ОПиУ (блокирующее)
 *   - количество затрат с include_in_pl = false (информационно)
 *   - наличие уже обработанных затрат (аномалия — блокирующее)
 *   - затраты в категориях вне каталога маркетплейса (нераспознанные)
 *   - контрольная сумма для сверки с PLDocument после закрытия
 */
final class PreflightCostsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function getCostsStats(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT
                COUNT(*)                                                        AS total,
                COUNT(*) FILTER (WHERE c.document_id IS NOT NULL)              AS already_processed,
                COUNT(*) FILTER (
                    WHERE m.id IS NULL OR m.pl_category_id IS NULL
                )                                                               AS without_pl_mapping,
                -- Решения по ОПиУ нет: маппинга нет либо он включён в ОПиУ
                -- без статьи — такая затрата молча не попадёт в ОПиУ.
                -- Осознанное исключение (include_in_pl = false) — решение.
                COUNT(*) FILTER (
                    WHERE m.id IS NULL OR (m.include_in_pl = true AND m.pl_category_id IS NULL)
                )                                                               AS without_pl_decision,
                COUNT(*) FILTER (
                    WHERE m.id IS NOT NULL AND m.include_in_pl = false
                )                                                               AS excluded_from_pl,
                COALESCE(
                    SUM(CASE WHEN c.operation_type = 'storno' THEN -ABS(c.amount) ELSE ABS(c.amount) END)
                        FILTER (WHERE m.include_in_pl = true AND m.pl_category_id IS NOT NULL),
                    0
                )                                                               AS net_amount_for_pl
            FROM marketplace_costs c
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ],
        ) ?: [
            'total' => 0,
            'already_processed' => 0,
            'without_pl_mapping' => 0,
            'without_pl_decision' => 0,
            'excluded_from_pl' => 0,
            'net_amount_for_pl' => '0',
        ];
    }

    /**
     * Затраты в категориях, которых нет в каталоге маркетплейса, — строка на
     * категорию (название + код): неизвестная услуга Ozon (`ozon_unknown_<type_id>`),
     * удержание WB, не описанное в WbCostCategory, и легаси-корзина
     * `ozon_other_service`. Код нужен в ключе: у `ozon_unknown_<type_id>`, чьего
     * типа нет в справочнике Ozon, название одинаковое, и разные услуги слились бы.
     *
     * У легаси-корзины одна категория на все неизвестные услуги, поэтому
     * название берётся из description — там исходное имя услуги.
     *
     * decided — по категории у компании есть решение по ОПиУ (условие
     * without_pl_decision из getCostsStats); блокировать ли такие строки,
     * решает вызывающий.
     *
     * @param list<string> $knownCodes коды каталога; всё остальное — нераспознанное
     *
     * @return list<array{service_name: string, category_code: string, count: int, decided: bool}>
     */
    public function getUnrecognizedCosts(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
        array $knownCodes,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                CASE WHEN cc.code = 'ozon_other_service' THEN c.description ELSE cc.name END AS service_name,
                cc.code                                                                        AS category_code,
                COUNT(c.id)                                                                    AS count,
                NOT (m.id IS NULL OR (m.include_in_pl = true AND m.pl_category_id IS NULL))   AS decided
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = cc.id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
              AND cc.code NOT IN (:knownCodes)
            GROUP BY 1, 2, 4
            ORDER BY COUNT(c.id) DESC, 1, 2
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
                'knownCodes' => $knownCodes,
            ],
            [
                'knownCodes' => ArrayParameterType::STRING,
            ],
        );

        return array_map(
            static fn (array $row): array => [
                'service_name' => (string) $row['service_name'],
                'category_code' => (string) $row['category_code'],
                'count' => (int) $row['count'],
                'decided' => (bool) $row['decided'],
            ],
            $rows,
        );
    }

    /**
     * Категории затрат без решения по ОПиУ за период (условие without_pl_decision
     * из getCostsStats).
     * Используется для отображения пользователю что именно нужно замапить.
     *
     * @return array<int, array{category_id: string, category_name: string, category_code: string, costs_count: int|string}>
     */
    public function getCategoriesWithoutMapping(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                cc.id   AS category_id,
                cc.name AS category_name,
                cc.code AS category_code,
                COUNT(mc.id) AS costs_count
            FROM marketplace_costs mc
            JOIN marketplace_cost_categories cc ON mc.category_id = cc.id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = cc.id
                AND m.company_id = mc.company_id
            WHERE mc.company_id  = :companyId
              AND mc.marketplace = :marketplace
              AND mc.cost_date >= :periodFrom
              AND mc.cost_date <= :periodTo
              AND (m.id IS NULL OR (m.include_in_pl = true AND m.pl_category_id IS NULL))
            GROUP BY cc.id, cc.name, cc.code
            ORDER BY COUNT(mc.id) DESC
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ],
        );
    }

    /**
     * Детализация по категориям которые войдут в PLDocument.
     * Используется для debug эндпоинта — показывает что именно будет создано.
     */
    public function getCostsCategoryBreakdown(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        // net_amount / costs_amount / storno_amount — классификация по operation_type.
        // После Phase 2B operation_type гарантированно NOT NULL (см. UnprocessedCostsQuery).
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                mcc.code                                                        AS category_code,
                mcc.name                                                        AS category_name,
                m.pl_category_id                                                AS pl_category_id,
                m.include_in_pl                                                 AS include_in_pl,
                m.is_negative                                                   AS is_negative,
                COUNT(c.id)                                                     AS count,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN 0
                    ELSE ABS(c.amount)
                END)                                                            AS costs_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN ABS(c.amount)
                    ELSE 0
                END)                                                            AS storno_amount,
                SUM(CASE
                    WHEN (c.operation_type = 'storno')
                    THEN -ABS(c.amount)
                    ELSE ABS(c.amount)
                END)                                                            AS net_amount,
                COUNT(c.id) FILTER (WHERE c.document_id IS NOT NULL)           AS already_processed
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories mcc ON mcc.id = c.category_id
            LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
            GROUP BY mcc.code, mcc.name, m.pl_category_id, m.include_in_pl, m.is_negative
            ORDER BY mcc.name ASC
            SQL,
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ],
        );
    }
}
