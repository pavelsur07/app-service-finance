<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL-запросы для preflight проверок этапа COSTS.
 *
 * Проверяет:
 *   - количество затрат за период
 *   - количество затрат без маппинга к ОПиУ (предупреждение)
 *   - количество затрат с include_in_pl = false (информационно)
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
            'SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE c.document_id IS NOT NULL) AS already_processed,
                COUNT(*) FILTER (
                    WHERE m.id IS NULL OR m.pl_category_id IS NULL
                ) AS without_pl_mapping,
                COUNT(*) FILTER (
                    WHERE m.id IS NOT NULL AND m.include_in_pl = false
                ) AS excluded_from_pl
             FROM marketplace_costs c
             LEFT JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
             WHERE c.company_id = :companyId
               AND c.marketplace = :marketplace
               AND c.cost_date >= :periodFrom
               AND c.cost_date <= :periodTo',
            [
                'companyId'   => $companyId,
                'marketplace' => $marketplace,
                'periodFrom'  => $periodFrom,
                'periodTo'    => $periodTo,
            ],
        ) ?: ['total' => 0, 'already_processed' => 0, 'without_pl_mapping' => 0, 'excluded_from_pl' => 0];
    }
}
