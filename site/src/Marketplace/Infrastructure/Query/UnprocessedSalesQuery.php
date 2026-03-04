<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Агрегирует необработанные продажи по маппингу AmountSource → PLCategory.
 *
 * JOIN с marketplace_sale_mappings (operationType = 'sale')
 * даёт несколько строк на одну продажу (по количеству маппингов).
 *
 * WHERE document_id IS NULL — только необработанные записи.
 */
final class UnprocessedSalesQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{
     *     pl_category_id: string,
     *     project_direction_id: ?string,
     *     is_negative: bool,
     *     description_template: ?string,
     *     sort_order: int,
     *     total_amount: string
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
                m.project_direction_id,
                m.is_negative,
                m.description_template,
                m.sort_order,
                SUM(
                    CASE m.amount_source
                        WHEN 'sale_gross'      THEN s.price_per_unit * s.quantity
                        WHEN 'sale_revenue'    THEN s.total_revenue
                        WHEN 'sale_cost_price' THEN COALESCE(s.cost_price, 0) * s.quantity
                        ELSE 0
                    END
                ) AS total_amount
            FROM marketplace_sales s
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = s.company_id
                AND m.marketplace = s.marketplace
                AND m.operation_type = 'sale'
                AND m.is_active = true
            WHERE s.company_id = :companyId
                AND s.marketplace = :marketplace
                AND s.document_id IS NULL
                AND s.sale_date >= :periodFrom
                AND s.sale_date <= :periodTo
            GROUP BY
                m.pl_category_id,
                m.project_direction_id,
                m.is_negative,
                m.description_template,
                m.sort_order
            HAVING SUM(
                CASE m.amount_source
                    WHEN 'sale_gross'      THEN s.price_per_unit * s.quantity
                    WHEN 'sale_revenue'    THEN s.total_revenue
                    WHEN 'sale_cost_price' THEN COALESCE(s.cost_price, 0) * s.quantity
                    ELSE 0
                END
            ) != 0
            ORDER BY m.sort_order ASC
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'companyId' => $companyId,
            'marketplace' => $marketplace,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
        ]);
    }
}
