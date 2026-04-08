<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для preflight проверок этапа COSTS.
 *
 * Проверяет:
 *   - количество затрат за период
 *   - количество затрат без маппинга к ОПиУ (блокирующее)
 *   - количество затрат с include_in_pl = false (информационно)
 *   - наличие уже обработанных затрат (аномалия — блокирующее)
 *   - нераспознанные service names (блокирующее)
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
                COUNT(*) FILTER (
                    WHERE m.id IS NOT NULL AND m.include_in_pl = false
                )                                                               AS excluded_from_pl,
                COALESCE(SUM(c.amount) FILTER (WHERE m.include_in_pl = true AND m.pl_category_id IS NOT NULL), 0)
                                                                                AS net_amount_for_pl
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
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?: [
            'total'              => 0,
            'already_processed'  => 0,
            'without_pl_mapping' => 0,
            'excluded_from_pl'   => 0,
            'net_amount_for_pl'  => '0',
        ];
    }

    /**
     * Количество нераспознанных service names за период.
     * Нераспознанные = category_code = 'ozon_other_service'.
     *
     * Блокирует закрытие — нельзя закрывать с неизвестными операциями.
     */
    public function getUnknownServiceNamesCount(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): int {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
            SELECT COUNT(c.id)
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories cc ON cc.id = c.category_id
            WHERE c.company_id  = :companyId
              AND c.marketplace = :marketplace
              AND c.cost_date  >= :periodFrom
              AND c.cost_date  <= :periodTo
              AND cc.code       = 'ozon_other_service'
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );
    }

    /**
     * Категории затрат без маппинга к ОПиУ за период.
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
              AND mc.cost_date BETWEEN :periodFrom AND :periodTo
              AND (m.id IS NULL OR m.pl_category_id IS NULL)
            GROUP BY cc.id, cc.name, cc.code
            ORDER BY costs_count DESC
            SQL,
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
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
        return $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT
                mcc.code                                                        AS category_code,
                mcc.name                                                        AS category_name,
                m.pl_category_id                                                AS pl_category_id,
                m.include_in_pl                                                 AS include_in_pl,
                m.is_negative                                                   AS is_negative,
                COUNT(c.id)                                                     AS count,
                SUM(CASE WHEN c.amount > 0 THEN c.amount  ELSE 0 END)          AS costs_amount,
                SUM(CASE WHEN c.amount < 0 THEN ABS(c.amount) ELSE 0 END)      AS storno_amount,
                SUM(c.amount)                                                   AS net_amount,
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
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        );
    }
}
