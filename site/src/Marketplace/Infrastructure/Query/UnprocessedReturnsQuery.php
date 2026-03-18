<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Агрегирует необработанные возвраты по маппингу AmountSource → PLCategory.
 *
 * LEFT JOIN marketplace_sales — для получения pricePerUnit/costPrice оригинальной продажи.
 *
 * Fallback логика для return_gross / return_cost_price:
 *   Если sale_id IS NULL (нет связанной продажи) — используем refund_amount.
 *   Это актуально для Ozon где возвраты не всегда матчатся с продажами.
 *
 * WHERE document_id IS NULL — только необработанные записи.
 */
final class UnprocessedReturnsQuery
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
                        WHEN 'return_refund'
                            THEN COALESCE(r.refund_amount, 0)
                        WHEN 'return_gross'
                            THEN COALESCE(
                                ms.price_per_unit * r.quantity,
                                r.refund_amount,
                                0
                            )
                        WHEN 'return_cost_price'
                            THEN COALESCE(
                                ms.cost_price * r.quantity,
                                0
                            )
                        ELSE 0
                    END
                ) AS total_amount
            FROM marketplace_returns r
            LEFT JOIN marketplace_sales ms
                ON ms.id = r.sale_id
            INNER JOIN marketplace_sale_mappings m
                ON m.company_id = r.company_id
                AND m.marketplace = r.marketplace
                AND m.operation_type = 'return'
                AND m.is_active = true
            WHERE r.company_id = :companyId
                AND r.marketplace = :marketplace
                AND r.document_id IS NULL
                AND r.return_date >= :periodFrom
                AND r.return_date <= :periodTo
            GROUP BY
                m.pl_category_id,
                m.project_direction_id,
                m.is_negative,
                m.description_template,
                m.sort_order
            HAVING SUM(
                CASE m.amount_source
                    WHEN 'return_refund'
                        THEN COALESCE(r.refund_amount, 0)
                    WHEN 'return_gross'
                        THEN COALESCE(
                            ms.price_per_unit * r.quantity,
                            r.refund_amount,
                            0
                        )
                    WHEN 'return_cost_price'
                        THEN COALESCE(
                            ms.cost_price * r.quantity,
                            0
                        )
                    ELSE 0
                END
            ) != 0
            ORDER BY m.sort_order ASC
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'companyId'   => $companyId,
            'marketplace' => $marketplace,
            'periodFrom'  => $periodFrom,
            'periodTo'    => $periodTo,
        ]);
    }
}
