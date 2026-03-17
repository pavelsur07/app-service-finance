<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Агрегирует необработанные расходы маркетплейса по категориям ОПиУ.
 *
 * Маппинг: MarketplaceCost → marketplace_cost_pl_mappings → pl_category_id.
 *
 * Условия включения в ОПиУ:
 *   - маппинг существует (JOIN)
 *   - include_in_pl = true
 *   - pl_category_id задан
 *
 * WHERE document_id IS NULL — только необработанные записи.
 */
final class UnprocessedCostsQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{
     *     pl_category_id: string,
     *     total_amount: string,
     *     description: string,
     *     is_negative: bool,
     *     sort_order: int
     * }>
     */
    public function execute(
        string $companyId,
        string $marketplace,
        string $periodFrom,
        string $periodTo,
    ): array {
        $sql = <<<'SQL'
            SELECT
                m.pl_category_id,
                SUM(c.amount)   AS total_amount,
                mcc.name        AS description,
                m.is_negative   AS is_negative,
                m.sort_order    AS sort_order
            FROM marketplace_costs c
            INNER JOIN marketplace_cost_categories mcc
                ON mcc.id = c.category_id
            INNER JOIN marketplace_cost_pl_mappings m
                ON m.cost_category_id = c.category_id
                AND m.company_id = c.company_id
            WHERE c.company_id = :companyId
                AND c.marketplace = :marketplace
                AND c.document_id IS NULL
                AND c.cost_date >= :periodFrom
                AND c.cost_date <= :periodTo
                AND m.include_in_pl = true
                AND m.pl_category_id IS NOT NULL
            GROUP BY
                m.pl_category_id,
                mcc.name,
                m.is_negative,
                m.sort_order
            HAVING SUM(c.amount) != 0
            ORDER BY m.sort_order ASC, mcc.name ASC
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ]);
    }
}
